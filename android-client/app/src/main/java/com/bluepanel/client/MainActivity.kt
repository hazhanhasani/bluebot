package com.bluepanel.client

import android.Manifest
import android.app.Activity
import android.content.pm.PackageManager
import android.net.VpnService
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.weight
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.bluepanel.client.data.BluePanelApi
import com.bluepanel.client.data.ServiceSummary
import com.bluepanel.client.data.SessionStore
import com.bluepanel.client.vpn.BluePanelVpnService
import com.bluepanel.client.vpn.VpnConnectionState
import kotlinx.coroutines.launch

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { BluePanelApp() }
    }
}

private val BluePanelColors = darkColorScheme(
    primary = Color(0xFF35A7FF),
    secondary = Color(0xFF72D6FF),
    background = Color(0xFF07111F),
    surface = Color(0xFF0D1B2E),
    onPrimary = Color.White,
    onBackground = Color(0xFFF2F7FF),
    onSurface = Color(0xFFF2F7FF),
)

@Composable
private fun BluePanelApp() {
    MaterialTheme(colorScheme = BluePanelColors) {
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

            LaunchedEffect(loggedIn) {
                if (loggedIn) refreshServices()
            }

            Surface(modifier = Modifier.fillMaxSize()) {
                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .background(
                            Brush.verticalGradient(
                                listOf(Color(0xFF07111F), Color(0xFF0A1730), Color(0xFF07111F)),
                            ),
                        ),
                ) {
                    if (!loggedIn) {
                        LoginScreen(
                            loading = loading,
                            error = error,
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
                        Dashboard(
                            username = store.username(),
                            services = services,
                            loading = loading,
                            error = error,
                            vpnState = vpnState,
                            onRefresh = ::refreshServices,
                            onConnect = { service ->
                                if (Build.VERSION.SDK_INT >= 33 &&
                                    ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
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
                            },
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
}

@Composable
private fun LoginScreen(
    loading: Boolean,
    error: String?,
    onLogin: (String, String) -> Unit,
) {
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }

    Column(
        modifier = Modifier.fillMaxSize().padding(horizontal = 24.dp),
        verticalArrangement = Arrangement.Center,
    ) {
        Box(
            modifier = Modifier
                .size(72.dp)
                .background(
                    Brush.linearGradient(listOf(Color(0xFF1687FF), Color(0xFF65D8FF))),
                    CircleShape,
                ),
            contentAlignment = Alignment.Center,
        ) {
            Text("B", fontSize = 32.sp, fontWeight = FontWeight.Black, color = Color.White)
        }
        Spacer(Modifier.height(20.dp))
        Text("Blue Panel", fontSize = 30.sp, fontWeight = FontWeight.Black)
        Text("اتصال اختصاصی، ساده و یک‌لمسی", color = Color(0xFFA7BAD3))
        Spacer(Modifier.height(28.dp))
        OutlinedTextField(
            value = username,
            onValueChange = { username = it },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            label = { Text("نام کاربری") },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii),
        )
        Spacer(Modifier.height(12.dp))
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            label = { Text("رمز عبور") },
            visualTransformation = PasswordVisualTransformation(),
        )
        if (!error.isNullOrBlank()) {
            Spacer(Modifier.height(12.dp))
            Text(error, color = Color(0xFFFF8A80), fontSize = 13.sp)
        }
        Spacer(Modifier.height(20.dp))
        Button(
            onClick = { onLogin(username, password) },
            enabled = !loading && username.isNotBlank() && password.isNotBlank(),
            modifier = Modifier.fillMaxWidth().height(54.dp),
            shape = RoundedCornerShape(16.dp),
        ) {
            if (loading) {
                CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = Color.White)
            } else {
                Text("ورود به بلوپنل", fontWeight = FontWeight.Bold)
            }
        }
        Spacer(Modifier.height(12.dp))
        Text(
            "نام کاربری و رمز را از ربات بلوپنل دریافت کنید.",
            color = Color(0xFF8094AF),
            fontSize = 12.sp,
        )
    }
}

@Composable
private fun Dashboard(
    username: String,
    services: List<ServiceSummary>,
    loading: Boolean,
    error: String?,
    vpnState: VpnConnectionState,
    onRefresh: () -> Unit,
    onConnect: (ServiceSummary) -> Unit,
    onDisconnect: () -> Unit,
    onLogout: () -> Unit,
) {
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(horizontal = 18.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Spacer(Modifier.height(14.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column {
                    Text("Blue Panel", fontSize = 26.sp, fontWeight = FontWeight.Black)
                    Text(username, color = Color(0xFF8297B4), fontSize = 12.sp)
                }
                OutlinedButton(onClick = onLogout, shape = RoundedCornerShape(14.dp)) { Text("خروج") }
            }
            Spacer(Modifier.height(12.dp))
            ConnectionHero(vpnState, onDisconnect)
            Spacer(Modifier.height(8.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column {
                    Text("اشتراک‌های من", fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
                    Text("${services.size} سرویس", color = Color(0xFF8297B4), fontSize = 12.sp)
                }
                OutlinedButton(onClick = onRefresh, enabled = !loading) { Text("بروزرسانی") }
            }
            if (!error.isNullOrBlank()) {
                Spacer(Modifier.height(10.dp))
                Text(error, color = Color(0xFFFF8A80), fontSize = 13.sp)
            }
        }

        if (loading && services.isEmpty()) {
            item {
                Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
            }
        } else if (services.isEmpty()) {
            item {
                Card(
                    colors = CardDefaults.cardColors(containerColor = Color(0xFF0D1B2E)),
                    shape = RoundedCornerShape(22.dp),
                ) {
                    Text(
                        "هنوز اشتراک فعالی برای این حساب وجود ندارد.",
                        modifier = Modifier.padding(22.dp),
                        color = Color(0xFFA7BAD3),
                    )
                }
            }
        } else {
            items(services, key = { it.id }) { service ->
                ServiceCard(service, vpnState, onConnect, onDisconnect)
            }
        }
        item { Spacer(Modifier.height(28.dp)) }
    }
}

@Composable
private fun ConnectionHero(state: VpnConnectionState, onDisconnect: () -> Unit) {
    val (title, detail, active) = when (state) {
        VpnConnectionState.Disconnected -> Triple("آماده اتصال", "یک سرویس را انتخاب کنید", false)
        VpnConnectionState.Connecting -> Triple("در حال اتصال…", "در حال آماده‌سازی مسیر امن", true)
        is VpnConnectionState.Connected -> Triple("متصل", state.productName, true)
        is VpnConnectionState.Error -> Triple("اتصال ناموفق", state.message, false)
    }
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(26.dp),
        colors = CardDefaults.cardColors(containerColor = Color(0xFF102642)),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(20.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                Modifier.size(52.dp).background(if (active) Color(0xFF22C984) else Color(0xFF24415F), CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Text(if (active) "✓" else "○", fontSize = 25.sp, fontWeight = FontWeight.Black)
            }
            Spacer(Modifier.width(14.dp))
            Column(Modifier.weight(1f)) {
                Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                Text(detail, color = Color(0xFFA7BAD3), fontSize = 12.sp, maxLines = 2)
            }
            if (state is VpnConnectionState.Connected || state is VpnConnectionState.Connecting) {
                OutlinedButton(onClick = onDisconnect) { Text("قطع") }
            }
        }
    }
}

@Composable
private fun ServiceCard(
    service: ServiceSummary,
    vpnState: VpnConnectionState,
    onConnect: (ServiceSummary) -> Unit,
    onDisconnect: () -> Unit,
) {
    val connected = vpnState is VpnConnectionState.Connected && vpnState.serviceId == service.id
    val connecting = vpnState is VpnConnectionState.Connecting
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = Color(0xFF0D1B2E)),
        shape = RoundedCornerShape(22.dp),
    ) {
        Column(Modifier.padding(18.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Column(Modifier.weight(1f)) {
                    Text(service.productName.ifBlank { service.username }, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                    Text(service.username, color = Color(0xFF8094AF), fontSize = 12.sp)
                }
                StatusPill(service.status, service.supported)
            }
            if (service.note.isNotBlank()) {
                Spacer(Modifier.height(8.dp))
                Text(service.note, color = Color(0xFFA7BAD3), fontSize = 12.sp, maxLines = 2)
            }
            Spacer(Modifier.height(16.dp))
            if (connected) {
                OutlinedButton(onClick = onDisconnect, modifier = Modifier.fillMaxWidth()) { Text("قطع اتصال") }
            } else {
                Button(
                    onClick = { onConnect(service) },
                    enabled = service.supported && !connecting,
                    modifier = Modifier.fillMaxWidth().height(48.dp),
                    shape = RoundedCornerShape(14.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF1687FF)),
                ) {
                    Text(if (service.supported) "اتصال" else "فعلاً پشتیبانی نمی‌شود", fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
private fun StatusPill(status: String, supported: Boolean) {
    val ok = supported && status.lowercase() in setOf("active", "enabled", "online", "send_on_hold", "sendedwarn")
    val bg = if (ok) Color(0x3322C984) else Color(0x33FFB74D)
    val fg = if (ok) Color(0xFF6BE6AE) else Color(0xFFFFC46A)
    Text(
        text = if (!supported) "نامعتبر" else status.ifBlank { "فعال" },
        color = fg,
        fontSize = 11.sp,
        modifier = Modifier.background(bg, RoundedCornerShape(50)).padding(horizontal = 10.dp, vertical = 5.dp),
    )
}
