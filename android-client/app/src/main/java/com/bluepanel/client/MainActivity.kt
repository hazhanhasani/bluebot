package com.bluepanel.client

import android.Manifest
import android.app.Activity
import android.content.pm.PackageManager
import android.net.VpnService
import android.os.Build
import android.os.Bundle
import android.os.SystemClock
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.weight
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.bluepanel.client.data.AppUpdateInfo
import com.bluepanel.client.data.BluePanelApi
import com.bluepanel.client.data.ServiceSummary
import com.bluepanel.client.data.SessionStore
import com.bluepanel.client.data.TrafficInfo
import com.bluepanel.client.vpn.BluePanelVpnService
import com.bluepanel.client.vpn.VpnConnectionState
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { BlueVpnApp() }
    }
}

private val BlueVpnColors = darkColorScheme(
    primary = Color(0xFF4DD7E4),
    secondary = Color(0xFF8BEAF3),
    background = Color(0xFF080B10),
    surface = Color(0xFF11151C),
    onPrimary = Color(0xFF031719),
    onBackground = Color(0xFFF8FBFF),
    onSurface = Color(0xFFF8FBFF),
)

private val Accent = Color(0xFF41D6DE)
private val Muted = Color(0xFF88939F)

@Composable
private fun BlueVpnApp() {
    MaterialTheme(colorScheme = BlueVpnColors) {
        CompositionLocalProvider(androidx.compose.ui.platform.LocalLayoutDirection provides LayoutDirection.Rtl) {
            val context = LocalContext.current
            val store = remember { SessionStore(context) }
            val api = remember { BluePanelApi(store) }
            val scope = rememberCoroutineScope()
            var loggedIn by remember { mutableStateOf(store.token() != null) }
            var services by remember { mutableStateOf<List<ServiceSummary>>(emptyList()) }
            var loading by remember { mutableStateOf(loggedIn) }
            var error by remember { mutableStateOf<String?>(null) }
            var pendingServiceId by remember { mutableStateOf<String?>(null) }
            var updateInfo by remember { mutableStateOf<AppUpdateInfo?>(null) }
            val vpnState by BluePanelVpnService.state.collectAsState()

            val vpnPermission = rememberLauncherForActivityResult(
                ActivityResultContracts.StartActivityForResult(),
            ) { result ->
                val id = pendingServiceId
                pendingServiceId = null
                if (result.resultCode == Activity.RESULT_OK && id != null) {
                    BluePanelVpnService.connect(context, id)
                } else if (id != null) {
                    error = "برای اتصال، مجوز VPN لازم است."
                }
            }

            val notificationPermission = rememberLauncherForActivityResult(
                ActivityResultContracts.RequestPermission(),
            ) { }

            fun refreshServices() {
                scope.launch {
                    loading = true
                    error = null
                    runCatching { api.services() }
                        .onSuccess { services = it }
                        .onFailure {
                            error = it.message ?: "دریافت سرویس‌ها ناموفق بود"
                            if ((it.message ?: "").contains("Authentication", ignoreCase = true)) {
                                store.clear()
                                loggedIn = false
                            }
                        }
                    loading = false
                }
            }

            fun connect(service: ServiceSummary) {
                if (Build.VERSION.SDK_INT >= 33 &&
                    ContextCompat.checkSelfPermission(
                        context,
                        Manifest.permission.POST_NOTIFICATIONS,
                    ) != PackageManager.PERMISSION_GRANTED
                ) {
                    notificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
                }

                val intent = VpnService.prepare(context)
                if (intent == null) {
                    BluePanelVpnService.connect(context, service.id)
                } else {
                    pendingServiceId = service.id
                    vpnPermission.launch(intent)
                }
            }

            LaunchedEffect(loggedIn) {
                if (loggedIn) refreshServices()
            }

            LaunchedEffect(Unit) {
                var intervalSeconds = 21_600L
                while (true) {
                    runCatching { api.updateInfo() }
                        .onSuccess { info ->
                            intervalSeconds = info.checkIntervalSeconds.coerceAtLeast(3_600L)
                            updateInfo = info.takeIf {
                                it.latestVersionCode > BuildConfig.VERSION_CODE
                            }
                        }
                    delay(intervalSeconds * 1_000L)
                }
            }

            Surface(modifier = Modifier.fillMaxSize()) {
                if (!loggedIn) {
                    LoginScreen(
                        loading = loading,
                        error = error,
                        updateInfo = updateInfo,
                        onLogin = { username, password ->
                            scope.launch {
                                loading = true
                                error = null
                                runCatching { api.login(username, password) }
                                    .onSuccess {
                                        store.saveSession(it.accessToken, it.username)
                                        loggedIn = true
                                    }
                                    .onFailure { error = it.message ?: "ورود ناموفق بود" }
                                loading = false
                            }
                        },
                    )
                } else {
                    PremiumDashboard(
                        username = store.username(),
                        services = services,
                        updateInfo = updateInfo,
                        loading = loading,
                        error = error,
                        vpnState = vpnState,
                        onRefresh = ::refreshServices,
                        onConnect = ::connect,
                        onDisconnect = { BluePanelVpnService.disconnect(context) },
                        onLogout = {
                            BluePanelVpnService.disconnect(context)
                            scope.launch {
                                api.logout()
                                services = emptyList()
                                loggedIn = false
                                error = null
                            }
                        },
                    )
                }
            }
        }
    }
}

@Composable
private fun LoginScreen(
    loading: Boolean,
    error: String?,
    updateInfo: AppUpdateInfo?,
    onLogin: (String, String) -> Unit,
) {
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    listOf(Color(0xFF070A0F), Color(0xFF0B1319), Color(0xFF071014)),
                ),
            ),
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(horizontal = 26.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Image(
                painter = painterResource(R.drawable.blue_vpn_icon),
                contentDescription = "Blue VPN",
                contentScale = ContentScale.Crop,
                modifier = Modifier.size(92.dp).clip(RoundedCornerShape(24.dp)),
            )
            Spacer(Modifier.height(18.dp))
            Text("Blue VPN", fontSize = 30.sp, fontWeight = FontWeight.Black)
            Text("اتصال امن، سریع و یک‌لمسی", color = Muted, fontSize = 13.sp)

            if (updateInfo != null) {
                Spacer(Modifier.height(18.dp))
                UpdateNotice(updateInfo)
            }

            Spacer(Modifier.height(28.dp))
            OutlinedTextField(
                value = username,
                onValueChange = { username = it },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                label = { Text("نام کاربری") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii),
                shape = RoundedCornerShape(18.dp),
            )
            Spacer(Modifier.height(12.dp))
            OutlinedTextField(
                value = password,
                onValueChange = { password = it },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                label = { Text("رمز عبور") },
                visualTransformation = PasswordVisualTransformation(),
                shape = RoundedCornerShape(18.dp),
            )

            if (!error.isNullOrBlank()) {
                Spacer(Modifier.height(12.dp))
                Text(error, color = Color(0xFFFF8D8D), fontSize = 13.sp)
            }

            Spacer(Modifier.height(20.dp))
            Button(
                onClick = { onLogin(username, password) },
                enabled = !loading && username.isNotBlank() && password.isNotBlank(),
                modifier = Modifier.fillMaxWidth().height(56.dp),
                shape = RoundedCornerShape(18.dp),
                colors = ButtonDefaults.buttonColors(containerColor = Accent),
            ) {
                if (loading) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(22.dp),
                        strokeWidth = 2.dp,
                        color = Color(0xFF041517),
                    )
                } else {
                    Text("ورود به Blue VPN", fontWeight = FontWeight.ExtraBold)
                }
            }

            Spacer(Modifier.height(12.dp))
            Text(
                "نام کاربری و رمز مخصوص سرویس را از ربات دریافت کنید.",
                color = Color(0xFF65717E),
                fontSize = 11.sp,
            )
        }
    }
}

@Composable
private fun PremiumDashboard(
    username: String,
    services: List<ServiceSummary>,
    updateInfo: AppUpdateInfo?,
    loading: Boolean,
    error: String?,
    vpnState: VpnConnectionState,
    onRefresh: () -> Unit,
    onConnect: (ServiceSummary) -> Unit,
    onDisconnect: () -> Unit,
    onLogout: () -> Unit,
) {
    var selectedId by remember { mutableStateOf<String?>(null) }
    var menuOpen by remember { mutableStateOf(false) }

    LaunchedEffect(services, vpnState) {
        val connectedId = (vpnState as? VpnConnectionState.Connected)?.serviceId
        selectedId = when {
            connectedId != null -> connectedId
            services.any { it.id == selectedId } -> selectedId
            else -> services.firstOrNull()?.id
        }
    }

    val selected = services.firstOrNull { it.id == selectedId } ?: services.firstOrNull()
    val connected = vpnState as? VpnConnectionState.Connected
    val isConnected = connected != null
    val isConnecting = vpnState is VpnConnectionState.Connecting

    var elapsedSeconds by remember { mutableStateOf(0L) }
    LaunchedEffect(connected?.connectedAtElapsedRealtime) {
        val startedAt = connected?.connectedAtElapsedRealtime
        if (startedAt == null) {
            elapsedSeconds = 0L
            return@LaunchedEffect
        }
        while (true) {
            elapsedSeconds = ((SystemClock.elapsedRealtime() - startedAt) / 1_000L).coerceAtLeast(0L)
            delay(1_000L)
        }
    }

    val background = if (isConnected) {
        Brush.verticalGradient(
            listOf(
                Color(0xFF15958F),
                Color(0xFF08736F),
                Color(0xFF053D3D),
                Color(0xFF071115),
            ),
        )
    } else {
        Brush.verticalGradient(
            listOf(
                Color(0xFF202124),
                Color(0xFF15171A),
                Color(0xFF0B0E12),
                Color(0xFF07090C),
            ),
        )
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(background)
            .statusBarsPadding(),
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(horizontal = 20.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.height(10.dp))

            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box {
                    Box(
                        modifier = Modifier
                            .size(42.dp)
                            .clip(CircleShape)
                            .background(Color(0x22000000))
                            .clickable { menuOpen = true },
                        contentAlignment = Alignment.Center,
                    ) {
                        Text("☰", fontSize = 20.sp, fontWeight = FontWeight.Bold)
                    }
                    DropdownMenu(
                        expanded = menuOpen,
                        onDismissRequest = { menuOpen = false },
                    ) {
                        DropdownMenuItem(
                            text = { Text("بروزرسانی سرویس") },
                            onClick = {
                                menuOpen = false
                                onRefresh()
                            },
                        )
                        DropdownMenuItem(
                            text = { Text("خروج از حساب") },
                            onClick = {
                                menuOpen = false
                                onLogout()
                            },
                        )
                    }
                }

                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text("Blue VPN", fontWeight = FontWeight.Black, fontSize = 18.sp)
                    Text(username, color = Color.White.copy(alpha = 0.54f), fontSize = 10.sp)
                }

                Box(
                    modifier = Modifier
                        .size(42.dp)
                        .clip(CircleShape)
                        .background(Color(0x22000000))
                        .clickable(enabled = !loading) { onRefresh() },
                    contentAlignment = Alignment.Center,
                ) {
                    Text("↻", fontSize = 21.sp, fontWeight = FontWeight.Bold)
                }
            }

            Spacer(Modifier.height(14.dp))

            StatusStrip(
                connected = isConnected,
                connecting = isConnecting,
                serviceCount = services.size,
            )

            Spacer(Modifier.height(14.dp))

            if (services.isNotEmpty()) {
                LazyRow(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    items(services, key = { it.id }) { service ->
                        ServiceSelectorPill(
                            service = service,
                            selected = service.id == selected?.id,
                            connected = connected?.serviceId == service.id,
                            onClick = {
                                if (!isConnected && !isConnecting) selectedId = service.id
                            },
                        )
                    }
                }
            }

            if (updateInfo != null) {
                Spacer(Modifier.height(10.dp))
                UpdateNotice(updateInfo)
            }

            if (!error.isNullOrBlank()) {
                Spacer(Modifier.height(10.dp))
                ErrorNotice(error)
            }

            Spacer(Modifier.height(8.dp))

            ConnectionStage(
                state = vpnState,
                elapsedSeconds = elapsedSeconds,
                selected = selected,
            )

            Spacer(Modifier.height(8.dp))

            PowerControl(
                connected = isConnected,
                connecting = isConnecting,
                enabled = selected?.supported == true || isConnected || isConnecting,
                onClick = {
                    when {
                        isConnected || isConnecting -> onDisconnect()
                        selected != null && selected.supported -> onConnect(selected)
                    }
                },
            )

            Spacer(Modifier.height(14.dp))

            ConnectionCaption(
                state = vpnState,
                selected = selected,
            )

            Spacer(Modifier.weight(1f))

            TrafficDock(
                traffic = connected?.traffic,
                expiresAt = connected?.expiresAt,
                fallbackStatus = selected?.status.orEmpty(),
            )

            Spacer(Modifier.height(16.dp))
        }
    }
}

@Composable
private fun StatusStrip(
    connected: Boolean,
    connecting: Boolean,
    serviceCount: Int,
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            text = when {
                connected -> "On"
                connecting -> "..."
                else -> "Off"
            },
            color = Color(0xFF111419),
            fontWeight = FontWeight.Black,
            fontSize = 12.sp,
            modifier = Modifier
                .background(Color.White, RoundedCornerShape(50))
                .padding(horizontal = 15.dp, vertical = 7.dp),
        )
        Spacer(Modifier.width(14.dp))
        Text("Xray", color = Color.White.copy(alpha = 0.78f), fontWeight = FontWeight.Bold, fontSize = 12.sp)
        Spacer(Modifier.width(14.dp))
        Text(
            "$serviceCount سرویس",
            color = Color.White.copy(alpha = 0.60f),
            fontWeight = FontWeight.Bold,
            fontSize = 12.sp,
        )
    }
}

@Composable
private fun ServiceSelectorPill(
    service: ServiceSummary,
    selected: Boolean,
    connected: Boolean,
    onClick: () -> Unit,
) {
    val label = service.productName.ifBlank { service.username }
    val background = when {
        connected -> Accent.copy(alpha = 0.20f)
        selected -> Color.White.copy(alpha = 0.16f)
        else -> Color.Black.copy(alpha = 0.18f)
    }

    Row(
        modifier = Modifier
            .clip(RoundedCornerShape(50))
            .background(background)
            .clickable(onClick = onClick)
            .padding(horizontal = 13.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(9.dp)
                .background(
                    if (service.supported) {
                        if (connected) Accent else Color(0xFF7E8B96)
                    } else {
                        Color(0xFFFFA65C)
                    },
                    CircleShape,
                ),
        )
        Spacer(Modifier.width(7.dp))
        Text(
            label,
            color = Color.White.copy(alpha = if (selected || connected) 1f else 0.68f),
            fontWeight = if (selected || connected) FontWeight.ExtraBold else FontWeight.Medium,
            fontSize = 11.sp,
            maxLines = 1,
        )
    }
}

@Composable
private fun ConnectionStage(
    state: VpnConnectionState,
    elapsedSeconds: Long,
    selected: ServiceSummary?,
) {
    val connected = state as? VpnConnectionState.Connected
    val active = connected != null || state is VpnConnectionState.Connecting

    Box(
        modifier = Modifier.fillMaxWidth().height(300.dp),
        contentAlignment = Alignment.Center,
    ) {
        GlobeBackdrop(active = active)

        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            if (connected != null) {
                Text(
                    formatElapsed(elapsedSeconds),
                    fontSize = 39.sp,
                    fontWeight = FontWeight.Black,
                    letterSpacing = 1.sp,
                )
                Spacer(Modifier.height(9.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                    MetricText("⇣", formatBytes(connected.traffic.usedBytes), "مصرف")
                    MetricText("⇡", formatBytes(connected.traffic.remainingBytes), "باقی‌مانده")
                }
            } else {
                Text(
                    if (state is VpnConnectionState.Connecting) "در حال اتصال…" else "آماده اتصال",
                    fontSize = 25.sp,
                    fontWeight = FontWeight.Black,
                )
                Spacer(Modifier.height(8.dp))
                Text(
                    selected?.productName?.ifBlank { selected.username }.orEmpty()
                        .ifBlank { "سرویس خود را انتخاب کنید" },
                    color = Color.White.copy(alpha = 0.56f),
                    fontSize = 12.sp,
                    maxLines = 1,
                )
            }
        }
    }
}

@Composable
private fun GlobeBackdrop(active: Boolean) {
    Canvas(modifier = Modifier.size(285.dp)) {
        val center = Offset(size.width / 2f, size.height / 2f)
        val radius = size.minDimension * 0.38f
        val glow = if (active) Accent else Color.White

        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(
                    glow.copy(alpha = if (active) 0.13f else 0.05f),
                    Color.Transparent,
                ),
                center = center,
                radius = radius * 1.55f,
            ),
            radius = radius * 1.55f,
            center = center,
        )

        drawCircle(
            color = glow.copy(alpha = 0.10f),
            radius = radius,
            center = center,
            style = Stroke(width = 2f),
        )

        drawOval(
            color = glow.copy(alpha = 0.08f),
            topLeft = Offset(center.x - radius * 0.55f, center.y - radius),
            size = Size(radius * 1.1f, radius * 2f),
            style = Stroke(width = 1.5f),
        )
        drawOval(
            color = glow.copy(alpha = 0.07f),
            topLeft = Offset(center.x - radius, center.y - radius * 0.45f),
            size = Size(radius * 2f, radius * 0.9f),
            style = Stroke(width = 1.5f),
        )

        for (fraction in listOf(-0.52f, 0f, 0.52f)) {
            drawLine(
                color = glow.copy(alpha = 0.055f),
                start = Offset(center.x - radius * 0.88f, center.y + radius * fraction),
                end = Offset(center.x + radius * 0.88f, center.y + radius * fraction),
                strokeWidth = 1.2f,
            )
        }

        val dots = listOf(
            Offset(-0.48f, -0.18f),
            Offset(-0.26f, 0.08f),
            Offset(0.02f, -0.32f),
            Offset(0.28f, -0.04f),
            Offset(0.46f, 0.22f),
            Offset(0.12f, 0.38f),
            Offset(-0.38f, 0.32f),
        )
        dots.forEach { dot ->
            drawCircle(
                color = glow.copy(alpha = if (active) 0.32f else 0.16f),
                radius = 3.8f,
                center = Offset(center.x + radius * dot.x, center.y + radius * dot.y),
            )
        }
    }
}

@Composable
private fun MetricText(icon: String, value: String, label: String) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text("$icon $value", fontSize = 11.sp, fontWeight = FontWeight.Bold)
        Text(label, color = Color.White.copy(alpha = 0.44f), fontSize = 9.sp)
    }
}

@Composable
private fun PowerControl(
    connected: Boolean,
    connecting: Boolean,
    enabled: Boolean,
    onClick: () -> Unit,
) {
    val top = if (connected) Color(0xFF5BE7E8) else Color(0xFFE2E5E8)
    val bottom = if (connected) Color(0xFF169C9A) else Color(0xFF5B6066)

    Box(
        modifier = Modifier
            .width(104.dp)
            .height(186.dp)
            .clip(RoundedCornerShape(54.dp))
            .background(Color(0xAA080A0D))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(8.dp),
        contentAlignment = if (connected) Alignment.BottomCenter else Alignment.TopCenter,
    ) {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .height(116.dp)
                .clip(RoundedCornerShape(48.dp))
                .background(Brush.verticalGradient(listOf(top, bottom))),
            contentAlignment = Alignment.Center,
        ) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text(
                    "⏻",
                    color = if (connected) Color.White else Color(0xFF22262A),
                    fontSize = 31.sp,
                    fontWeight = FontWeight.Black,
                )
                Spacer(Modifier.height(7.dp))
                Text(
                    when {
                        connecting -> "..."
                        connected -> "Stop"
                        else -> "Start"
                    },
                    color = if (connected) Color.White else Color(0xFF22262A),
                    fontSize = 12.sp,
                    fontWeight = FontWeight.ExtraBold,
                )
            }
        }
    }
}

@Composable
private fun ConnectionCaption(
    state: VpnConnectionState,
    selected: ServiceSummary?,
) {
    val title = when (state) {
        VpnConnectionState.Disconnected -> "متصل نیست"
        VpnConnectionState.Connecting -> "در حال برقراری اتصال"
        is VpnConnectionState.Connected -> "متصل"
        is VpnConnectionState.Error -> "اتصال ناموفق"
    }
    val subtitle = when (state) {
        VpnConnectionState.Disconnected -> "برای اتصال دکمه Start را لمس کنید"
        VpnConnectionState.Connecting -> "چند لحظه صبر کنید…"
        is VpnConnectionState.Connected -> state.productName.ifBlank { state.username }
        is VpnConnectionState.Error -> state.message
    }

    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text(title, fontWeight = FontWeight.Black, fontSize = 18.sp)
        Spacer(Modifier.height(4.dp))
        Text(
            subtitle.ifBlank {
                selected?.productName?.ifBlank { selected.username }.orEmpty()
            },
            color = Color.White.copy(alpha = 0.52f),
            fontSize = 10.sp,
            maxLines = 2,
        )
    }
}

@Composable
private fun TrafficDock(
    traffic: TrafficInfo?,
    expiresAt: Long?,
    fallbackStatus: String,
) {
    val total = traffic?.totalBytes ?: 0L
    val used = traffic?.usedBytes ?: 0L
    val remaining = traffic?.remainingBytes ?: 0L
    val progress = if (total > 0L) {
        (used.toDouble() / total.toDouble()).coerceIn(0.0, 1.0).toFloat()
    } else {
        0f
    }

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(24.dp),
        colors = CardDefaults.cardColors(containerColor = Color(0xAA101317)),
    ) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 14.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Column {
                    Text(
                        if (traffic != null) "${formatBytes(remaining)} باقی‌مانده" else "اطلاعات سرویس",
                        fontWeight = FontWeight.Black,
                        fontSize = 14.sp,
                    )
                    Text(
                        if (traffic != null && total > 0) {
                            "${formatBytes(used)} از ${formatBytes(total)} مصرف شده"
                        } else {
                            fallbackStatus.ifBlank { "آماده" }
                        },
                        color = Muted,
                        fontSize = 10.sp,
                    )
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text("انقضا", color = Muted, fontSize = 9.sp)
                    Text(formatExpiry(expiresAt), fontSize = 10.sp, fontWeight = FontWeight.Bold)
                }
            }

            Spacer(Modifier.height(10.dp))
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(5.dp)
                    .clip(RoundedCornerShape(50))
                    .background(Color.White.copy(alpha = 0.08f)),
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxHeight()
                        .fillMaxWidth(progress)
                        .clip(RoundedCornerShape(50))
                        .background(Accent),
                )
            }
        }
    }
}

@Composable
private fun UpdateNotice(info: AppUpdateInfo) {
    val uriHandler = LocalUriHandler.current
    val required = BuildConfig.VERSION_CODE < info.minimumVersionCode

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = Color(0xCC15242B)),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Column(Modifier.weight(1f)) {
                Text(
                    if (required) "بروزرسانی ضروری" else "نسخه جدید آماده است",
                    fontWeight = FontWeight.ExtraBold,
                    fontSize = 12.sp,
                )
                Text(
                    "Blue VPN ${info.latestVersionName}",
                    color = Color.White.copy(alpha = 0.55f),
                    fontSize = 10.sp,
                )
            }
            Button(
                onClick = { uriHandler.openUri(info.downloadUrl) },
                shape = RoundedCornerShape(50),
                colors = ButtonDefaults.buttonColors(containerColor = Accent),
            ) {
                Text("دریافت", fontSize = 10.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun ErrorNotice(message: String) {
    Text(
        text = message,
        color = Color(0xFFFFB0A7),
        fontSize = 11.sp,
        modifier = Modifier
            .fillMaxWidth()
            .background(Color(0x332D0F0F), RoundedCornerShape(14.dp))
            .padding(horizontal = 12.dp, vertical = 9.dp),
    )
}

private fun formatElapsed(seconds: Long): String {
    val safe = seconds.coerceAtLeast(0L)
    val hours = safe / 3_600L
    val minutes = (safe % 3_600L) / 60L
    val secs = safe % 60L
    return "%02d:%02d:%02d".format(hours, minutes, secs)
}

private fun formatBytes(bytes: Long): String {
    val safe = bytes.coerceAtLeast(0L).toDouble()
    val gb = 1024.0 * 1024.0 * 1024.0
    val mb = 1024.0 * 1024.0
    return when {
        safe >= gb -> "%.2f GB".format(safe / gb)
        safe >= mb -> "%.0f MB".format(safe / mb)
        safe > 0 -> "%.0f KB".format(safe / 1024.0)
        else -> "0 MB"
    }
}

private fun formatExpiry(expiresAt: Long?): String {
    if (expiresAt == null || expiresAt <= 0L) return "نامحدود"
    return runCatching {
        val formatter = DateTimeFormatter.ofPattern("yyyy/MM/dd")
            .withZone(ZoneId.systemDefault())
        formatter.format(Instant.ofEpochSecond(expiresAt))
    }.getOrDefault("—")
}
