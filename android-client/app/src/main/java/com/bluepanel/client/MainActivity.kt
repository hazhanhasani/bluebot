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
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectVerticalDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
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
import androidx.compose.runtime.mutableFloatStateOf
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
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.IntOffset
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
import java.util.Locale
import kotlin.math.roundToInt
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

    val visualActive = isConnected || isConnecting
    val backgroundTop by animateColorAsState(
        targetValue = when {
            isConnected -> Color(0xFF15958F)
            isConnecting -> Color(0xFF0C5354)
            else -> Color(0xFF202124)
        },
        animationSpec = tween(650),
        label = "backgroundTop",
    )
    val backgroundMiddle by animateColorAsState(
        targetValue = if (visualActive) Color(0xFF075F5E) else Color(0xFF14171B),
        animationSpec = tween(650),
        label = "backgroundMiddle",
    )
    val backgroundBottom by animateColorAsState(
        targetValue = if (visualActive) Color(0xFF06191B) else Color(0xFF07090C),
        animationSpec = tween(650),
        label = "backgroundBottom",
    )
    val background = Brush.verticalGradient(
        listOf(backgroundTop, backgroundMiddle, backgroundBottom),
    )

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
                        MenuGlyph()
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
                    RefreshGlyph(spinning = loading)
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
                modifier = Modifier
                    .fillMaxWidth()
                    .weight(1f)
                    .heightIn(min = 190.dp),
                state = vpnState,
                elapsedSeconds = elapsedSeconds,
                selected = selected,
            )

            Spacer(Modifier.height(4.dp))

            PowerControl(
                connected = isConnected,
                connecting = isConnecting,
                enabled = selected?.supported == true || isConnected || isConnecting,
                onToggle = {
                    when {
                        isConnected || isConnecting -> onDisconnect()
                        selected != null && selected.supported -> onConnect(selected)
                    }
                },
            )

            Spacer(Modifier.height(8.dp))

            ConnectionCaption(
                state = vpnState,
                selected = selected,
            )

            Spacer(Modifier.height(10.dp))

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
    modifier: Modifier = Modifier,
    state: VpnConnectionState,
    elapsedSeconds: Long,
    selected: ServiceSummary?,
) {
    val connected = state as? VpnConnectionState.Connected
    val active = connected != null || state is VpnConnectionState.Connecting
    val contentAlpha by animateFloatAsState(
        targetValue = if (state is VpnConnectionState.Connecting) 0.72f else 1f,
        animationSpec = tween(320),
        label = "stageContent",
    )

    BoxWithConstraints(
        modifier = modifier,
        contentAlignment = Alignment.Center,
    ) {
        val candidate = minOf(maxWidth * 0.82f, maxHeight * 0.94f)
        val globeSize = when {
            candidate < 170.dp -> 170.dp
            candidate > 285.dp -> 285.dp
            else -> candidate
        }

        GlobeBackdrop(
            active = active,
            modifier = Modifier.size(globeSize),
        )

        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.background(Color.Transparent),
        ) {
            if (connected != null) {
                CompositionLocalProvider(
                    androidx.compose.ui.platform.LocalLayoutDirection provides LayoutDirection.Ltr,
                ) {
                    Text(
                        formatElapsed(elapsedSeconds),
                        fontSize = 38.sp,
                        fontWeight = FontWeight.Black,
                        letterSpacing = 1.sp,
                        color = Color.White.copy(alpha = contentAlpha),
                    )
                }
                Spacer(Modifier.height(9.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(24.dp)) {
                    MetricText("↓", formatBytes(connected.traffic.usedBytes), "مصرف")
                    MetricText(
                        "↑",
                        if (connected.traffic.totalBytes <= 0L) "نامحدود"
                        else formatBytes(connected.traffic.remainingBytes),
                        "باقی‌مانده",
                    )
                }
            } else {
                Text(
                    if (state is VpnConnectionState.Connecting) "در حال اتصال…" else "آماده اتصال",
                    fontSize = 25.sp,
                    fontWeight = FontWeight.Black,
                    color = Color.White.copy(alpha = contentAlpha),
                )
                Spacer(Modifier.height(8.dp))
                Text(
                    selected?.let { it.productName.ifBlank { it.username } }.orEmpty()
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
private fun GlobeBackdrop(
    active: Boolean,
    modifier: Modifier = Modifier,
) {
    val pulseTransition = rememberInfiniteTransition(label = "globePulse")
    val pulse by pulseTransition.animateFloat(
        initialValue = 0.82f,
        targetValue = 1f,
        animationSpec = infiniteRepeatable(
            animation = tween(if (active) 1_600 else 2_600),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "globePulseValue",
    )

    Canvas(modifier = modifier) {
        val center = Offset(size.width / 2f, size.height / 2f)
        val radius = size.minDimension * (0.365f + (0.015f * pulse))
        val glow = if (active) Accent else Color.White
        val glowAlpha = if (active) 0.09f + 0.06f * pulse else 0.035f + 0.02f * pulse

        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(
                    glow.copy(alpha = glowAlpha),
                    Color.Transparent,
                ),
                center = center,
                radius = radius * 1.55f,
            ),
            radius = radius * 1.55f,
            center = center,
        )

        drawCircle(
            color = glow.copy(alpha = if (active) 0.12f else 0.08f),
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
        dots.forEachIndexed { index, dot ->
            val dotPulse = if ((index % 2) == 0) pulse else (1f - pulse * 0.25f)
            drawCircle(
                color = glow.copy(alpha = if (active) 0.18f + 0.22f * dotPulse else 0.13f),
                radius = 3.2f + (1.2f * dotPulse),
                center = Offset(center.x + radius * dot.x, center.y + radius * dot.y),
            )
        }
    }
}

@Composable
private fun MetricText(icon: String, value: String, label: String) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        CompositionLocalProvider(
            androidx.compose.ui.platform.LocalLayoutDirection provides LayoutDirection.Ltr,
        ) {
            Text("$icon $value", fontSize = 11.sp, fontWeight = FontWeight.Bold)
        }
        Text(label, color = Color.White.copy(alpha = 0.48f), fontSize = 9.sp)
    }
}

@Composable
private fun PowerControl(
    connected: Boolean,
    connecting: Boolean,
    enabled: Boolean,
    onToggle: () -> Unit,
) {
    val density = LocalDensity.current
    val travelDp = 56.dp
    val travelPx = with(density) { travelDp.toPx() }
    val topPaddingPx = with(density) { 8.dp.toPx() }

    var dragDeltaPx by remember { mutableFloatStateOf(0f) }
    var dragging by remember { mutableStateOf(false) }

    val stateTargetPx = when {
        connecting -> travelPx * 0.5f
        connected -> travelPx
        else -> 0f
    }
    val animatedBasePx by animateFloatAsState(
        targetValue = stateTargetPx,
        animationSpec = tween(360),
        label = "powerThumbOffset",
    )
    val visualOffsetPx = if (dragging) {
        (stateTargetPx + dragDeltaPx).coerceIn(0f, travelPx)
    } else {
        animatedBasePx
    }

    val topColor by animateColorAsState(
        targetValue = if (connected) Color(0xFF64ECEC) else Color(0xFFF0F2F4),
        animationSpec = tween(320),
        label = "powerTop",
    )
    val bottomColor by animateColorAsState(
        targetValue = if (connected) Color(0xFF139D9C) else Color(0xFF747A80),
        animationSpec = tween(320),
        label = "powerBottom",
    )

    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            modifier = Modifier
                .width(106.dp)
                .height(176.dp)
                .clip(RoundedCornerShape(54.dp))
                .background(Color(0xCC05090C))
                .pointerInput(connected, connecting, enabled) {
                    if (!enabled || connecting) return@pointerInput
                    detectVerticalDragGestures(
                        onDragStart = {
                            dragging = true
                            dragDeltaPx = 0f
                        },
                        onVerticalDrag = { change, dragAmount ->
                            change.consume()
                            dragDeltaPx += dragAmount
                        },
                        onDragCancel = {
                            dragging = false
                            dragDeltaPx = 0f
                        },
                        onDragEnd = {
                            val finalPx = (stateTargetPx + dragDeltaPx).coerceIn(0f, travelPx)
                            val shouldToggle = if (connected) {
                                finalPx <= travelPx * 0.35f
                            } else {
                                finalPx >= travelPx * 0.65f
                            }
                            dragging = false
                            dragDeltaPx = 0f
                            if (shouldToggle) onToggle()
                        },
                    )
                }
                .clickable(enabled = enabled && !connecting) { onToggle() },
        ) {
            SwipeChevron(
                down = !connected,
                modifier = Modifier
                    .align(if (connected) Alignment.TopCenter else Alignment.BottomCenter)
                    .padding(vertical = 12.dp),
            )

            Box(
                modifier = Modifier
                    .offset {
                        IntOffset(
                            x = 0,
                            y = (topPaddingPx + visualOffsetPx).roundToInt(),
                        )
                    }
                    .align(Alignment.TopCenter)
                    .width(90.dp)
                    .height(104.dp)
                    .clip(RoundedCornerShape(47.dp))
                    .background(Brush.verticalGradient(listOf(topColor, bottomColor))),
                contentAlignment = Alignment.Center,
            ) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    PowerGlyph(
                        color = if (connected) Color.White else Color(0xFF252A2E),
                    )
                    Spacer(Modifier.height(8.dp))
                    Text(
                        when {
                            connecting -> "..."
                            connected -> "Stop"
                            else -> "Start"
                        },
                        color = if (connected) Color.White else Color(0xFF252A2E),
                        fontSize = 12.sp,
                        fontWeight = FontWeight.ExtraBold,
                    )
                }
            }
        }

        Spacer(Modifier.height(7.dp))
        Text(
            when {
                connecting -> "در حال اتصال…"
                connected -> "برای قطع، به بالا بکشید"
                else -> "برای اتصال، به پایین بکشید"
            },
            color = Color.White.copy(alpha = 0.48f),
            fontSize = 9.sp,
        )
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
        VpnConnectionState.Disconnected -> "دکمه را به پایین بکشید یا لمس کنید"
        VpnConnectionState.Connecting -> "چند لحظه صبر کنید…"
        is VpnConnectionState.Connected -> state.productName.ifBlank { state.username }
        is VpnConnectionState.Error -> state.message
    }

    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text(title, fontWeight = FontWeight.Black, fontSize = 18.sp)
        Spacer(Modifier.height(4.dp))
        Text(
            subtitle.ifBlank {
                selected?.let { it.productName.ifBlank { it.username } }.orEmpty()
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
    val targetProgress = if (total > 0L) {
        (used.toDouble() / total.toDouble()).coerceIn(0.0, 1.0).toFloat()
    } else {
        0f
    }
    val progress by animateFloatAsState(
        targetValue = targetProgress,
        animationSpec = tween(650),
        label = "trafficProgress",
    )
    val unlimited = traffic != null && total <= 0L

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(24.dp),
        colors = CardDefaults.cardColors(containerColor = Color(0xAA101317)),
    ) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 13.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column {
                    Text(
                        when {
                            traffic == null -> "اطلاعات سرویس"
                            unlimited -> "حجم نامحدود"
                            else -> "باقی‌مانده: ${formatBytes(remaining)}"
                        },
                        fontWeight = FontWeight.Black,
                        fontSize = 14.sp,
                    )
                    Text(
                        when {
                            traffic == null -> fallbackStatus.ifBlank { "آماده" }
                            unlimited -> "مصرف: ${formatBytes(used)}"
                            else -> "مصرف: ${formatBytes(used)} از ${formatBytes(total)}"
                        },
                        color = Muted,
                        fontSize = 10.sp,
                    )
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text("انقضا", color = Muted, fontSize = 9.sp)
                    CompositionLocalProvider(
                        androidx.compose.ui.platform.LocalLayoutDirection provides LayoutDirection.Ltr,
                    ) {
                        Text(formatExpiry(expiresAt), fontSize = 10.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }

            if (traffic != null && !unlimited) {
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
}

@Composable
private fun MenuGlyph(
    modifier: Modifier = Modifier.size(22.dp),
    color: Color = Color.White,
) {
    Canvas(modifier = modifier) {
        val stroke = size.minDimension * 0.11f
        val left = size.width * 0.12f
        val right = size.width * 0.88f
        for (fraction in listOf(0.24f, 0.5f, 0.76f)) {
            drawLine(
                color = color,
                start = Offset(left, size.height * fraction),
                end = Offset(right, size.height * fraction),
                strokeWidth = stroke,
                cap = StrokeCap.Round,
            )
        }
    }
}

@Composable
private fun RefreshGlyph(
    spinning: Boolean,
    modifier: Modifier = Modifier.size(22.dp),
    color: Color = Color.White,
) {
    val transition = rememberInfiniteTransition(label = "refreshSpin")
    val rotation by transition.animateFloat(
        initialValue = 0f,
        targetValue = 360f,
        animationSpec = infiniteRepeatable(
            animation = tween(if (spinning) 900 else 20_000),
            repeatMode = RepeatMode.Restart,
        ),
        label = "refreshRotation",
    )

    Canvas(modifier = modifier) {
        rotate(if (spinning) rotation else 0f) {
            val stroke = size.minDimension * 0.10f
            val inset = stroke * 1.8f
            drawArc(
                color = color,
                startAngle = -50f,
                sweepAngle = 285f,
                useCenter = false,
                topLeft = Offset(inset, inset),
                size = Size(size.width - inset * 2f, size.height - inset * 2f),
                style = Stroke(width = stroke, cap = StrokeCap.Round),
            )
            val tip = Offset(size.width * 0.79f, size.height * 0.18f)
            drawLine(
                color = color,
                start = tip,
                end = Offset(size.width * 0.93f, size.height * 0.27f),
                strokeWidth = stroke,
                cap = StrokeCap.Round,
            )
            drawLine(
                color = color,
                start = tip,
                end = Offset(size.width * 0.81f, size.height * 0.35f),
                strokeWidth = stroke,
                cap = StrokeCap.Round,
            )
        }
    }
}

@Composable
private fun PowerGlyph(
    color: Color,
    modifier: Modifier = Modifier.size(28.dp),
) {
    Canvas(modifier = modifier) {
        val stroke = size.minDimension * 0.11f
        drawArc(
            color = color,
            startAngle = -45f,
            sweepAngle = 270f,
            useCenter = false,
            topLeft = Offset(stroke * 1.4f, stroke * 1.4f),
            size = Size(size.width - stroke * 2.8f, size.height - stroke * 2.8f),
            style = Stroke(width = stroke, cap = StrokeCap.Round),
        )
        drawLine(
            color = color,
            start = Offset(size.width / 2f, size.height * 0.08f),
            end = Offset(size.width / 2f, size.height * 0.48f),
            strokeWidth = stroke,
            cap = StrokeCap.Round,
        )
    }
}

@Composable
private fun SwipeChevron(
    down: Boolean,
    modifier: Modifier = Modifier.size(18.dp),
) {
    Canvas(modifier = modifier) {
        val color = Color.White.copy(alpha = 0.42f)
        val stroke = size.minDimension * 0.11f
        val centerY = size.height * 0.5f
        val yShift = if (down) size.height * 0.13f else -size.height * 0.13f
        val left = Offset(size.width * 0.24f, centerY - yShift)
        val center = Offset(size.width * 0.5f, centerY + yShift)
        val right = Offset(size.width * 0.76f, centerY - yShift)
        drawLine(color, left, center, strokeWidth = stroke, cap = StrokeCap.Round)
        drawLine(color, center, right, strokeWidth = stroke, cap = StrokeCap.Round)
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
    return String.format(Locale.US, "%02d:%02d:%02d", hours, minutes, secs)
}

private fun formatBytes(bytes: Long): String {
    val safe = bytes.coerceAtLeast(0L).toDouble()
    val gb = 1024.0 * 1024.0 * 1024.0
    val mb = 1024.0 * 1024.0
    return when {
        safe >= gb -> String.format(Locale.US, "%.2f GB", safe / gb)
        safe >= mb -> String.format(Locale.US, "%.0f MB", safe / mb)
        safe > 0 -> String.format(Locale.US, "%.0f KB", safe / 1024.0)
        else -> "0 MB"
    }
}

private fun formatExpiry(expiresAt: Long?): String {
    if (expiresAt == null || expiresAt <= 0L) return "نامحدود"
    return runCatching {
        val formatter = DateTimeFormatter.ofPattern("yyyy/MM/dd", Locale.US)
            .withZone(ZoneId.systemDefault())
        formatter.format(Instant.ofEpochSecond(expiresAt))
    }.getOrDefault("—")
}
