package com.bluepanel.client.vpn

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.VpnService
import android.os.Build
import android.os.ParcelFileDescriptor
import android.os.SystemClock
import androidx.core.app.NotificationCompat
import com.bluepanel.client.MainActivity
import com.bluepanel.client.R
import com.bluepanel.client.data.BluePanelApi
import com.bluepanel.client.data.SessionStore
import com.bluepanel.client.data.isAuthenticationFailure
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class BluePanelVpnService : VpnService() {
    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    private val connectionLifecycle = VpnConnectionLifecycle(serviceScope) { stopTunnelOnly() }
    private var trafficMonitorJob: Job? = null
    private var vpnInterface: ParcelFileDescriptor? = null
    private var xrayEngine: XrayEngine? = null
    private lateinit var sessionStore: SessionStore
    private lateinit var api: BluePanelApi

    override fun onCreate() {
        super.onCreate()
        sessionStore = SessionStore(this)
        api = BluePanelApi(sessionStore)
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_DISCONNECT -> disconnect(stopService = true)
            ACTION_CONNECT -> {
                val serviceId = intent.getStringExtra(EXTRA_SERVICE_ID).orEmpty()
                val locationIndex = intent
                    .getIntExtra(EXTRA_LOCATION_INDEX, -1)
                    .takeIf { it >= 0 }
                if (serviceId.isBlank()) {
                    disconnect(stopService = true, finalState = VpnConnectionState.Error("سرویس انتخاب نشده است"))
                } else {
                    startForeground(NOTIFICATION_ID, notification("در حال اتصال…"))
                    connect(serviceId, locationIndex)
                }
            }
        }
        return START_NOT_STICKY
    }

    override fun onRevoke() {
        // Android can revoke the VPN from a binder thread.
        serviceScope.launch { disconnect(stopService = true) }
        super.onRevoke()
    }

    override fun onDestroy() {
        trafficMonitorJob?.cancel()
        trafficMonitorJob = null
        connectionLifecycle.disconnect {
            publish(_state.value.afterServiceStopped())
            stopForeground(STOP_FOREGROUND_REMOVE)
            serviceScope.cancel()
        }
        super.onDestroy()
    }

    private fun connect(serviceId: String, locationIndex: Int?) {
        trafficMonitorJob?.cancel()
        trafficMonitorJob = null
        publish(VpnConnectionState.Connecting)
        connectionLifecycle.connect(
            onError = { error ->
                val message = error.message?.take(180).orEmpty().ifBlank { "اتصال برقرار نشد" }
                publish(VpnConnectionState.Error(message, authenticationRequired = error.isAuthenticationFailure()))
                stopForeground(STOP_FOREGROUND_REMOVE)
                stopSelf()
            },
            prepare = {
                // Fetch while the OS VPN route is not active. The subscription is kept in memory only.
                val profile = api.service(serviceId)
                check(profile.status.lowercase() !in setOf("expired", "disabled", "limited", "end_of_time", "end_of_volume")) {
                    "این اشتراک در حال حاضر قابل اتصال نیست"
                }
                profile to api.connectionText(profile, locationIndex)
            },
        ) { attempt, (profile, sourceText) ->
            withContext(Dispatchers.IO) {
                currentCoroutineContext().ensureActive()
                val tun = Builder()
                    .setSession("Blue VPN")
                    .setMtu(1500)
                    .addAddress("172.19.0.1", 30)
                    .addAddress("fdfe:dcba:9876::1", 126)
                    .addRoute("0.0.0.0", 0)
                    .addRoute("::", 0)
                    .addDnsServer("1.1.1.1")
                    .addDnsServer("8.8.8.8")
                    .setBlocking(true)
                    .establish() ?: error("مجوز ایجاد VPN صادر نشد")

                vpnInterface = tun
                val engine = XrayEngine(this@BluePanelVpnService)
                xrayEngine = engine
                engine.start(tun.fd, sourceText)
            }
            currentCoroutineContext().ensureActive()

            val connectedAt = SystemClock.elapsedRealtime()
            publish(
                VpnConnectionState.Connected(
                    serviceId = serviceId,
                    locationIndex = locationIndex,
                    productName = profile.productName,
                    username = profile.username,
                    traffic = profile.traffic,
                    expiresAt = profile.expiresAt,
                    connectedAtElapsedRealtime = connectedAt,
                ),
            )
            startTrafficMonitor(serviceId, attempt)
            notifyState("متصل · ${profile.productName.ifBlank { profile.username }}")
        }
    }

    private fun startTrafficMonitor(serviceId: String, attempt: Long) {
        trafficMonitorJob?.cancel()
        trafficMonitorJob = serviceScope.launch {
            while (true) {
                delay(30_000L)
                if (!connectionLifecycle.isCurrent(attempt)) break

                val current = _state.value as? VpnConnectionState.Connected ?: break
                if (current.serviceId != serviceId) break

                val profile = try {
                    api.service(serviceId)
                } catch (cancelled: CancellationException) {
                    throw cancelled
                } catch (error: Exception) {
                    if (!connectionLifecycle.isCurrent(attempt)) break
                    if (error.isAuthenticationFailure()) {
                        disconnect(
                            stopService = true,
                            finalState = VpnConnectionState.Error(
                                error.message.orEmpty(),
                                authenticationRequired = true,
                            ),
                        )
                        break
                    }
                    continue
                }
                if (!connectionLifecycle.isCurrent(attempt)) break
                val normalizedStatus = profile.status.lowercase()
                if (normalizedStatus in setOf("expired", "disabled", "limited", "end_of_time", "end_of_volume")) {
                    disconnect(
                        stopService = true,
                        finalState = VpnConnectionState.Error("اعتبار این سرویس به پایان رسیده است"),
                    )
                    break
                }

                val latest = _state.value as? VpnConnectionState.Connected ?: break
                if (latest.serviceId != serviceId) break

                publish(
                    latest.copy(
                        productName = profile.productName,
                        username = profile.username,
                        traffic = profile.traffic,
                        expiresAt = profile.expiresAt,
                    ),
                )
            }
        }
    }

    private fun disconnect(
        stopService: Boolean,
        finalState: VpnConnectionState = VpnConnectionState.Disconnected,
    ) {
        trafficMonitorJob?.cancel()
        trafficMonitorJob = null
        connectionLifecycle.disconnect {
            publish(finalState)
            stopForeground(STOP_FOREGROUND_REMOVE)
            if (stopService) stopSelf()
        }
    }

    private suspend fun stopTunnelOnly() = withContext(Dispatchers.IO) {
        runCatching { xrayEngine?.stop() }
        xrayEngine = null
        runCatching { vpnInterface?.close() }
        vpnInterface = null
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val manager = getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(
                NotificationChannel(
                    CHANNEL_ID,
                    "Blue VPN Connection",
                    NotificationManager.IMPORTANCE_LOW,
                ).apply {
                    description = "وضعیت اتصال امن Blue VPN"
                    setShowBadge(false)
                },
            )
        }
    }

    private fun notification(content: String): Notification {
        val openIntent = PendingIntent.getActivity(
            this,
            1,
            Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val disconnectIntent = PendingIntent.getService(
            this,
            2,
            Intent(this, BluePanelVpnService::class.java).setAction(ACTION_DISCONNECT),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_vpn_status)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(content)
            .setContentIntent(openIntent)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .addAction(0, "قطع اتصال", disconnectIntent)
            .build()
    }

    private fun notifyState(content: String) {
        getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, notification(content))
    }

    private fun publish(value: VpnConnectionState) {
        _state.value = value
    }

    companion object {
        private const val ACTION_CONNECT = "com.bluepanel.client.CONNECT"
        private const val ACTION_DISCONNECT = "com.bluepanel.client.DISCONNECT"
        private const val EXTRA_SERVICE_ID = "service_id"
        private const val EXTRA_LOCATION_INDEX = "location_index"
        private const val CHANNEL_ID = "bluepanel_vpn"
        private const val NOTIFICATION_ID = 2401

        private val _state = MutableStateFlow<VpnConnectionState>(VpnConnectionState.Disconnected)
        val state: StateFlow<VpnConnectionState> = _state.asStateFlow()

        fun connect(context: Context, serviceId: String, locationIndex: Int? = null) {
            val intent = Intent(context, BluePanelVpnService::class.java)
                .setAction(ACTION_CONNECT)
                .putExtra(EXTRA_SERVICE_ID, serviceId)
                .apply {
                    locationIndex?.let { putExtra(EXTRA_LOCATION_INDEX, it) }
                }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        fun disconnect(context: Context) {
            context.startService(Intent(context, BluePanelVpnService::class.java).setAction(ACTION_DISCONNECT))
        }
    }
}
