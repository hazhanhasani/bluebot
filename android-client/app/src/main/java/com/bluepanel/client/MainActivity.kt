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
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
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
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalHapticFeedback
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
import com.bluepanel.client.data.StoreCatalog
import com.bluepanel.client.data.StorePlan
import com.bluepanel.client.data.TrafficInfo
import com.bluepanel.client.data.VpnLocation
import com.bluepanel.client.ui.QrScannerOverlay
import com.bluepanel.client.util.PersianDateTime
import com.bluepanel.client.vpn.BluePanelVpnService
import com.bluepanel.client.vpn.VpnConnectionState
import java.util.Locale
import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.roundToInt
import kotlin.math.sin
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
            var registerOpen by remember { mutableStateOf(false) }
            var storeOpen by remember { mutableStateOf(false) }
            var services by remember { mutableStateOf<List<ServiceSummary>>(emptyList()) }
            var loading by remember { mutableStateOf(loggedIn) }
            var error by remember { mutableStateOf<String?>(null) }
            var pendingServiceId by remember { mutableStateOf<String?>(null) }
            var pendingLocationIndex by remember { mutableStateOf<Int?>(null) }
            var updateInfo by remember { mutableStateOf<AppUpdateInfo?>(null) }
            val vpnState by BluePanelVpnService.state.collectAsState()

            val vpnPermission = rememberLauncherForActivityResult(
                ActivityResultContracts.StartActivityForResult(),
            ) { result ->
                val id = pendingServiceId
                val locationIndex = pendingLocationIndex
                pendingServiceId = null
                pendingLocationIndex = null
                if (result.resultCode == Activity.RESULT_OK && id != null) {
                    BluePanelVpnService.connect(context, id, locationIndex)
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

            fun connect(service: ServiceSummary, locationIndex: Int?) {
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
                    BluePanelVpnService.connect(context, service.id, locationIndex)
                } else {
                    pendingServiceId = service.id
                    pendingLocationIndex = locationIndex
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
                when {
                    !loggedIn && registerOpen -> {
                        PhoneRegistrationScreen(
                            api = api,
                            sessionStore = store,
                            onBack = { registerOpen = false },
                            onAuthenticated = {
                                registerOpen = false
                                loggedIn = true
                                storeOpen = true
                            },
                        )
                    }

                    !loggedIn -> {
                        LoginScreen(
                            loading = loading,
                            error = error,
                            updateInfo = updateInfo,
                            onOpenRegister = { registerOpen = true },
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
                            onQrLogin = { payload ->
                                scope.launch {
                                    loading = true
                                    error = null
                                    runCatching { api.qrLogin(payload) }
                                        .onSuccess {
                                            store.saveSession(it.accessToken, it.username)
                                            loggedIn = true
                                        }
                                        .onFailure {
                                            error = it.message ?: "ورود با QR ناموفق بود"
                                        }
                                    loading = false
                                }
                            },
                        )
                    }

                    storeOpen -> {
                        StoreScreen(
                            api = api,
                            onBack = {
                                storeOpen = false
                                refreshServices()
                            },
                            onRequireAccount = {
                                BluePanelVpnService.disconnect(context)
                                scope.launch {
                                    api.logout()
                                    services = emptyList()
                                    storeOpen = false
                                    loggedIn = false
                                    registerOpen = true
                                    error = null
                                }
                            },
                            onPurchased = ::refreshServices,
                        )
                    }

                    else -> {
                        PremiumDashboard(
                            api = api,
                            username = store.username(),
                            services = services,
                            updateInfo = updateInfo,
                            loading = loading,
                            error = error,
                            vpnState = vpnState,
                            onRefresh = ::refreshServices,
                            onOpenStore = { storeOpen = true },
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
}

@Composable
private fun LoginScreen(
    loading: Boolean,
    error: String?,
    updateInfo: AppUpdateInfo?,
    onOpenRegister: () -> Unit,
    onLogin: (String, String) -> Unit,
    onQrLogin: (String) -> Unit,
) {
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var scannerOpen by remember { mutableStateOf(false) }

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

            Spacer(Modifier.height(10.dp))
            Button(
                onClick = onOpenRegister,
                enabled = !loading,
                modifier = Modifier.fillMaxWidth().height(52.dp),
                shape = RoundedCornerShape(18.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = Color(0xFF12333A),
                    contentColor = Color.White,
                ),
            ) {
                Text("ثبت‌نام با شماره موبایل و خرید سرویس", fontWeight = FontWeight.ExtraBold)
            }

            Spacer(Modifier.height(10.dp))
            Button(
                onClick = { scannerOpen = true },
                enabled = !loading,
                modifier = Modifier.fillMaxWidth().height(52.dp),
                shape = RoundedCornerShape(18.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = Color(0xFF17242B),
                    contentColor = Color.White,
                ),
            ) {
                Text("اسکن QR سرویس", fontWeight = FontWeight.ExtraBold)
            }

            Spacer(Modifier.height(12.dp))
            Text(
                "یا نام کاربری و رمز مخصوص همین سرویس را از ربات کپی کنید.",
                color = Color(0xFF65717E),
                fontSize = 11.sp,
            )
        }

        if (scannerOpen) {
            QrScannerOverlay(
                onResult = { payload ->
                    scannerOpen = false
                    onQrLogin(payload)
                },
                onClose = { scannerOpen = false },
            )
        }
    }
}

@Composable
private fun PhoneRegistrationScreen(
    api: BluePanelApi,
    sessionStore: SessionStore,
    onBack: () -> Unit,
    onAuthenticated: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var phone by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    var otpSent by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var helper by remember { mutableStateOf("شماره موبایل را وارد کنید تا حساب BlueVPN شما ساخته شود.") }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    listOf(Color(0xFF061014), Color(0xFF0A1D22), Color(0xFF071014)),
                ),
            )
            .statusBarsPadding(),
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp, vertical = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text("ساخت حساب", fontSize = 23.sp, fontWeight = FontWeight.Black)
                Text(
                    "بازگشت",
                    modifier = Modifier
                        .clip(RoundedCornerShape(50))
                        .clickable(enabled = !loading) { onBack() }
                        .padding(horizontal = 14.dp, vertical = 8.dp),
                    color = Accent,
                    fontWeight = FontWeight.Bold,
                )
            }

            Spacer(Modifier.height(28.dp))
            Image(
                painter = painterResource(R.drawable.blue_vpn_icon),
                contentDescription = "Blue VPN",
                modifier = Modifier.size(82.dp).clip(RoundedCornerShape(22.dp)),
            )
            Spacer(Modifier.height(20.dp))
            Text(
                if (otpSent) "تأیید شماره موبایل" else "خوش آمدید 👋",
                fontSize = 24.sp,
                fontWeight = FontWeight.Black,
            )
            Spacer(Modifier.height(8.dp))
            Text(helper, color = Muted, fontSize = 12.sp)

            Spacer(Modifier.height(28.dp))
            OutlinedTextField(
                value = phone,
                onValueChange = { if (!otpSent) phone = it },
                enabled = !otpSent && !loading,
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                label = { Text("شماره موبایل") },
                placeholder = { Text("0912...") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                shape = RoundedCornerShape(18.dp),
            )

            if (otpSent) {
                Spacer(Modifier.height(12.dp))
                OutlinedTextField(
                    value = code,
                    onValueChange = { code = it.filter(Char::isDigit).take(6) },
                    enabled = !loading,
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    label = { Text("کد ۶ رقمی") },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                    shape = RoundedCornerShape(18.dp),
                )
            }

            if (!error.isNullOrBlank()) {
                Spacer(Modifier.height(12.dp))
                ErrorNotice(error!!)
            }

            Spacer(Modifier.height(20.dp))
            Button(
                onClick = {
                    scope.launch {
                        loading = true
                        error = null
                        if (!otpSent) {
                            runCatching { api.requestMobileOtp(phone) }
                                .onSuccess {
                                    phone = it.phone
                                    otpSent = true
                                    helper = "کد تأیید برای $phone ارسال شد."
                                }
                                .onFailure { error = it.message ?: "ارسال کد ناموفق بود" }
                        } else {
                            runCatching { api.verifyMobileOtp(phone, code) }
                                .onSuccess {
                                    sessionStore.saveSession(it.accessToken, it.username)
                                    onAuthenticated()
                                }
                                .onFailure { error = it.message ?: "تأیید شماره ناموفق بود" }
                        }
                        loading = false
                    }
                },
                enabled = !loading && phone.isNotBlank() && (!otpSent || code.length == 6),
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
                    Text(
                        if (otpSent) "تأیید و ورود به فروشگاه" else "دریافت کد تأیید",
                        fontWeight = FontWeight.ExtraBold,
                    )
                }
            }

            if (otpSent) {
                Spacer(Modifier.height(10.dp))
                Text(
                    "تغییر شماره",
                    modifier = Modifier
                        .clickable(enabled = !loading) {
                            otpSent = false
                            code = ""
                            error = null
                            helper = "شماره موبایل را وارد کنید تا حساب BlueVPN شما ساخته شود."
                        }
                        .padding(10.dp),
                    color = Accent,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Bold,
                )
            }

            Spacer(Modifier.height(24.dp))
            Text(
                "اگر بعداً همین شماره را در ربات تأیید کنید، سرویس‌های خریداری‌شده به حساب تلگرام شما متصل می‌شوند.",
                color = Color.White.copy(alpha = 0.46f),
                fontSize = 11.sp,
            )
        }
    }
}

@Composable
private fun StoreScreen(
    api: BluePanelApi,
    onBack: () -> Unit,
    onRequireAccount: () -> Unit,
    onPurchased: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    val uriHandler = LocalUriHandler.current
    var catalog by remember { mutableStateOf<StoreCatalog?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var selectedGateway by remember { mutableStateOf("wallet") }
    var pendingOrderId by remember { mutableStateOf<String?>(null) }
    var statusMessage by remember { mutableStateOf<String?>(null) }

    fun refresh() {
        scope.launch {
            loading = true
            error = null
            runCatching { api.storeCatalog() }
                .onSuccess {
                    catalog = it
                    selectedGateway = when {
                        "blupal" in it.gateways -> "blupal"
                        "zarinpal" in it.gateways -> "zarinpal"
                        else -> "wallet"
                    }
                }
                .onFailure { error = it.message ?: "دریافت فروشگاه ناموفق بود" }
            loading = false
        }
    }

    LaunchedEffect(Unit) { refresh() }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    listOf(Color(0xFF071014), Color(0xFF0B1B20), Color(0xFF071014)),
                ),
            )
            .statusBarsPadding(),
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp, vertical = 20.dp),
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column {
                    Text("فروشگاه BlueVPN", fontSize = 22.sp, fontWeight = FontWeight.Black)
                    Text("انتخاب پلن و فعال‌سازی مستقیم داخل اپ", color = Muted, fontSize = 11.sp)
                }
                Text(
                    "بازگشت",
                    modifier = Modifier
                        .clip(RoundedCornerShape(50))
                        .clickable { onBack() }
                        .padding(horizontal = 14.dp, vertical = 8.dp),
                    color = Accent,
                    fontWeight = FontWeight.Bold,
                )
            }

            Spacer(Modifier.height(18.dp))

            catalog?.let { data ->
                if (data.requiresAccount) {
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(22.dp),
                        colors = CardDefaults.cardColors(containerColor = Color(0xD112262C)),
                    ) {
                        Column(
                            modifier = Modifier.padding(20.dp),
                            horizontalAlignment = Alignment.CenterHorizontally,
                        ) {
                            Text(
                                "برای خرید، حساب خود را متصل کنید",
                                fontSize = 17.sp,
                                fontWeight = FontWeight.Black,
                            )
                            Spacer(Modifier.height(8.dp))
                            Text(
                                "ورود با QR برای اتصال سرویس کافی است، اما خرید به یک حساب تأییدشده با شماره موبایل نیاز دارد.",
                                color = Color.White.copy(alpha = 0.60f),
                                fontSize = 11.sp,
                            )
                            Spacer(Modifier.height(16.dp))
                            Button(
                                onClick = onRequireAccount,
                                modifier = Modifier.fillMaxWidth().height(50.dp),
                                shape = RoundedCornerShape(16.dp),
                                colors = ButtonDefaults.buttonColors(containerColor = Accent),
                            ) {
                                Text("ساخت / اتصال حساب با شماره موبایل", fontWeight = FontWeight.ExtraBold)
                            }
                        }
                    }
                } else {
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(20.dp),
                        colors = CardDefaults.cardColors(containerColor = Color(0xB3162228)),
                    ) {
                        Column(Modifier.padding(16.dp)) {
                            Text("روش پرداخت", fontWeight = FontWeight.ExtraBold)
                            Spacer(Modifier.height(10.dp))
                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.spacedBy(8.dp),
                            ) {
                                data.gateways.forEach { gateway ->
                                    val label = when (gateway) {
                                        "blupal" -> "BluePal"
                                        "zarinpal" -> "زرین‌پال"
                                        else -> "کیف پول"
                                    }
                                    Button(
                                        onClick = { selectedGateway = gateway },
                                        modifier = Modifier.weight(1f),
                                        shape = RoundedCornerShape(14.dp),
                                        colors = ButtonDefaults.buttonColors(
                                            containerColor = if (selectedGateway == gateway) Accent else Color(0xFF18242A),
                                        ),
                                    ) {
                                        Text(label, fontSize = 10.sp, fontWeight = FontWeight.Bold)
                                    }
                                }
                            }
                            Spacer(Modifier.height(8.dp))
                            Text(
                                "موجودی کیف پول: ${String.format(Locale.US, "%,d", data.balance)} تومان",
                                color = Color.White.copy(alpha = 0.62f),
                                fontSize = 11.sp,
                            )
                        }
                    }
                }
            }

            if (!statusMessage.isNullOrBlank()) {
                Spacer(Modifier.height(12.dp))
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    colors = CardDefaults.cardColors(containerColor = Color(0xFF123038)),
                    shape = RoundedCornerShape(16.dp),
                ) {
                    Text(statusMessage!!, modifier = Modifier.padding(14.dp), fontSize = 12.sp)
                }
            }

            if (!error.isNullOrBlank()) {
                Spacer(Modifier.height(12.dp))
                ErrorNotice(error!!)
            }

            if (loading) {
                Spacer(Modifier.height(40.dp))
                Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Accent)
                }
            } else {
                Spacer(Modifier.height(14.dp))
                val products = catalog?.products.orEmpty()
                val requiresAccount = catalog?.requiresAccount == true
                if (products.isEmpty() && !requiresAccount) {
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(20.dp),
                    ) {
                        Column(Modifier.padding(20.dp)) {
                            Text("فعلاً پلن فعالی برای فروش وجود ندارد.", fontWeight = FontWeight.Bold)
                            Spacer(Modifier.height(8.dp))
                            Text(
                                "پس از فعال‌کردن محصولات در پنل، همین‌جا نمایش داده می‌شوند.",
                                color = Muted,
                                fontSize = 11.sp,
                            )
                        }
                    }
                } else if (!requiresAccount) {
                    products.forEach { plan ->
                        StorePlanCard(
                            plan = plan,
                            loading = loading,
                            onBuy = {
                                scope.launch {
                                    loading = true
                                    error = null
                                    statusMessage = null
                                    runCatching {
                                        api.checkout(plan.id, plan.panelId, selectedGateway)
                                    }.onSuccess { checkout ->
                                        if (checkout.status.equals("paid", ignoreCase = true)) {
                                            statusMessage = "✅ سرویس با موفقیت فعال شد."
                                            pendingOrderId = null
                                            onPurchased()
                                            refresh()
                                        } else {
                                            pendingOrderId = checkout.orderId
                                            statusMessage = "پرداخت ساخته شد. پس از پرداخت، «بررسی پرداخت» را بزنید."
                                            checkout.paymentUrl?.let(uriHandler::openUri)
                                        }
                                    }.onFailure {
                                        error = it.message ?: "ایجاد سفارش ناموفق بود"
                                    }
                                    loading = false
                                }
                            },
                        )
                        Spacer(Modifier.height(12.dp))
                    }
                }
            }

            pendingOrderId?.let { orderId ->
                Spacer(Modifier.height(4.dp))
                Button(
                    onClick = {
                        scope.launch {
                            loading = true
                            error = null
                            runCatching { api.orderStatus(orderId) }
                                .onSuccess { order ->
                                    if (order.status == "paid" && order.serviceId != null) {
                                        statusMessage = "✅ پرداخت تأیید و سرویس فعال شد."
                                        pendingOrderId = null
                                        onPurchased()
                                        refresh()
                                    } else if (order.status == "delivery_error") {
                                        statusMessage = "⚠️ پرداخت تأیید شده اما ساخت سرویس کامل نشده است؛ سفارش برای بررسی ثبت شد."
                                    } else {
                                        statusMessage = "پرداخت هنوز تأیید نشده است."
                                    }
                                }
                                .onFailure { error = it.message ?: "بررسی پرداخت ناموفق بود" }
                            loading = false
                        }
                    },
                    enabled = !loading,
                    modifier = Modifier.fillMaxWidth().height(54.dp),
                    shape = RoundedCornerShape(18.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = Accent),
                ) {
                    Text("بررسی پرداخت", fontWeight = FontWeight.ExtraBold)
                }
            }

            Spacer(Modifier.height(28.dp))
        }
    }
}

@Composable
private fun StorePlanCard(
    plan: StorePlan,
    loading: Boolean,
    onBuy: () -> Unit,
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(22.dp),
        colors = CardDefaults.cardColors(containerColor = Color(0xD1121C22)),
    ) {
        Column(Modifier.padding(17.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.Top,
            ) {
                Column(Modifier.weight(1f)) {
                    Text(plan.name, fontSize = 16.sp, fontWeight = FontWeight.Black)
                    Text(plan.panelName, color = Accent, fontSize = 10.sp, fontWeight = FontWeight.Bold)
                }
                Text(
                    "${String.format(Locale.US, "%,d", plan.price)} تومان",
                    fontWeight = FontWeight.Black,
                    fontSize = 14.sp,
                )
            }
            Spacer(Modifier.height(10.dp))
            Text(
                buildString {
                    append(if (plan.trafficGb <= 0) "حجم نامحدود" else "${plan.trafficGb} GB")
                    append("  •  ")
                    append(if (plan.timeDays <= 0) "بدون محدودیت زمانی" else "${plan.timeDays} روز")
                },
                color = Color.White.copy(alpha = 0.64f),
                fontSize = 11.sp,
            )
            if (plan.description.isNotBlank()) {
                Spacer(Modifier.height(7.dp))
                Text(plan.description, color = Muted, fontSize = 10.sp)
            }
            Spacer(Modifier.height(14.dp))
            Button(
                onClick = onBuy,
                enabled = !loading,
                modifier = Modifier.fillMaxWidth().height(48.dp),
                shape = RoundedCornerShape(15.dp),
                colors = ButtonDefaults.buttonColors(containerColor = Accent),
            ) {
                Text("خرید و فعال‌سازی", fontWeight = FontWeight.ExtraBold)
            }
        }
    }
}

@Composable
private fun PremiumDashboard(
    api: BluePanelApi,
    username: String,
    services: List<ServiceSummary>,
    updateInfo: AppUpdateInfo?,
    loading: Boolean,
    error: String?,
    vpnState: VpnConnectionState,
    onRefresh: () -> Unit,
    onOpenStore: () -> Unit,
    onConnect: (ServiceSummary, Int?) -> Unit,
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

    val automaticLocation = remember {
        VpnLocation(index = -1, name = "خودکار", flag = "🌐")
    }
    var locations by remember { mutableStateOf(listOf(automaticLocation)) }
    var selectedLocationIndex by remember { mutableStateOf(-1) }
    var locationsLoading by remember { mutableStateOf(false) }

    LaunchedEffect(selected?.id) {
        locations = listOf(automaticLocation)
        selectedLocationIndex = -1
        locationsLoading = false
        val service = selected
        if (service != null && service.supported) {
            locationsLoading = true
            val resolved = runCatching { api.serviceLocations(service.id) }
                .getOrElse { listOf(automaticLocation) }
                .ifEmpty { listOf(automaticLocation) }
            locations = resolved
            val connectedLocation = (vpnState as? VpnConnectionState.Connected)
                ?.takeIf { it.serviceId == service.id }
                ?.locationIndex
                ?: -1
            selectedLocationIndex = resolved
                .firstOrNull { it.index == connectedLocation }
                ?.index
                ?: resolved.first().index
            locationsLoading = false
        }
    }

    LaunchedEffect(connected?.serviceId, connected?.locationIndex) {
        if (connected != null && connected.serviceId == selected?.id) {
            val activeIndex = connected.locationIndex ?: -1
            if (locations.any { it.index == activeIndex }) {
                selectedLocationIndex = activeIndex
            }
        }
    }

    var visualState by remember { mutableStateOf<VpnConnectionState>(vpnState) }
    LaunchedEffect(vpnState) {
        val previous = visualState
        if (vpnState is VpnConnectionState.Disconnected &&
            previous is VpnConnectionState.Connected
        ) {
            delay(460L)
        }
        visualState = vpnState
    }
    val visualConnected = visualState as? VpnConnectionState.Connected
    val visualIsConnected = visualConnected != null
    val visualIsConnecting = visualState is VpnConnectionState.Connecting
    val stateTransitioning = visualState != vpnState

    var elapsedSeconds by remember { mutableStateOf(0L) }
    LaunchedEffect(visualConnected?.connectedAtElapsedRealtime) {
        val startedAt = visualConnected?.connectedAtElapsedRealtime
        if (startedAt == null) {
            elapsedSeconds = 0L
            return@LaunchedEffect
        }
        while (true) {
            elapsedSeconds = ((SystemClock.elapsedRealtime() - startedAt) / 1_000L).coerceAtLeast(0L)
            delay(1_000L)
        }
    }

    var nowEpochSeconds by remember {
        mutableStateOf(System.currentTimeMillis() / 1_000L)
    }
    LaunchedEffect(Unit) {
        while (true) {
            nowEpochSeconds = System.currentTimeMillis() / 1_000L
            delay(30_000L)
        }
    }

    val visualActive = visualIsConnected || visualIsConnecting
    val backgroundTop by animateColorAsState(
        targetValue = when {
            visualIsConnected -> Color(0xFF15958F)
            visualIsConnecting -> Color(0xFF0C5354)
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
        LivingBackground(active = visualActive)

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
                            text = { Text("🛍 فروشگاه و خرید سرویس") },
                            onClick = {
                                menuOpen = false
                                onOpenStore()
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
                    Spacer(Modifier.height(3.dp))
                    Text(
                        PersianDateTime.formatEpochSeconds(nowEpochSeconds, includeTime = true),
                        color = Color.White.copy(alpha = 0.70f),
                        fontSize = 10.sp,
                        fontWeight = FontWeight.SemiBold,
                        modifier = Modifier
                            .background(
                                Color.Black.copy(alpha = 0.12f),
                                RoundedCornerShape(50),
                            )
                            .padding(horizontal = 8.dp, vertical = 3.dp),
                    )
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
                connected = visualIsConnected,
                connecting = visualIsConnecting,
                serviceCount = services.size,
            )

            Spacer(Modifier.height(8.dp))

            if (updateInfo != null) {
                Spacer(Modifier.height(10.dp))
                UpdateNotice(updateInfo)
            }

            if (!error.isNullOrBlank()) {
                Spacer(Modifier.height(10.dp))
                ErrorNotice(error)
            }

            Spacer(Modifier.height(4.dp))

            LocationArcCarousel(
                locations = locations,
                selectedIndex = selectedLocationIndex,
                loading = locationsLoading,
                enabled = !visualIsConnecting && selected?.supported == true,
                onSelect = { location ->
                    if (location.index != selectedLocationIndex) {
                        selectedLocationIndex = location.index
                        val service = selected
                        val activeConnection = vpnState as? VpnConnectionState.Connected
                        if (
                            service != null &&
                            activeConnection?.serviceId == service.id &&
                            (activeConnection.locationIndex ?: -1) != location.index
                        ) {
                            onConnect(service, location.index.takeIf { it >= 0 })
                        }
                    }
                },
            )

            Spacer(Modifier.height(2.dp))

            ConnectionStage(
                modifier = Modifier
                    .fillMaxWidth()
                    .weight(1f)
                    .heightIn(min = 190.dp),
                state = visualState,
                elapsedSeconds = elapsedSeconds,
                selected = selected,
            )

            Spacer(Modifier.height(4.dp))

            PowerControl(
                connected = visualIsConnected,
                connecting = visualIsConnecting,
                enabled = !stateTransitioning &&
                    (selected?.supported == true || isConnected || isConnecting),
                onToggle = {
                    when {
                        isConnected || isConnecting -> onDisconnect()
                        selected != null && selected.supported -> onConnect(
                            selected,
                            selectedLocationIndex.takeIf { it >= 0 },
                        )
                    }
                },
            )

            Spacer(Modifier.height(8.dp))

            ConnectionCaption(
                state = visualState,
                selected = selected,
            )

            Spacer(Modifier.height(10.dp))

            TrafficDock(
                traffic = visualConnected?.traffic,
                expiresAt = visualConnected?.expiresAt,
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
            "${PersianDateTime.toPersianDigits(serviceCount.toString())} سرویس",
            color = Color.White.copy(alpha = 0.60f),
            fontWeight = FontWeight.Bold,
            fontSize = 12.sp,
        )
    }
}

@Composable
private fun LocationArcCarousel(
    locations: List<VpnLocation>,
    selectedIndex: Int,
    loading: Boolean,
    enabled: Boolean,
    onSelect: (VpnLocation) -> Unit,
) {
    val options = locations.ifEmpty {
        listOf(VpnLocation(index = -1, name = "خودکار", flag = "🌐"))
    }
    val selectedPosition = options.indexOfFirst { it.index == selectedIndex }.takeIf { it >= 0 } ?: 0
    val density = LocalDensity.current
    val haptic = LocalHapticFeedback.current
    var dragOffsetPx by remember(options, selectedIndex) { mutableFloatStateOf(0f) }

    CompositionLocalProvider(
        androidx.compose.ui.platform.LocalLayoutDirection provides LayoutDirection.Ltr,
    ) {
        BoxWithConstraints(
            modifier = Modifier
                .fillMaxWidth()
                .height(112.dp)
                .pointerInput(options, selectedPosition, enabled) {
                    if (!enabled || options.size <= 1) return@pointerInput
                    val spacing = size.width / 4.65f
                    detectHorizontalDragGestures(
                        onDragStart = { dragOffsetPx = 0f },
                        onHorizontalDrag = { change, dragAmount ->
                            change.consume()
                            dragOffsetPx = (dragOffsetPx + dragAmount)
                                .coerceIn(-spacing * 1.15f, spacing * 1.15f)
                        },
                        onDragCancel = { dragOffsetPx = 0f },
                        onDragEnd = {
                            val threshold = spacing * 0.28f
                            val delta = when {
                                dragOffsetPx <= -threshold -> 1
                                dragOffsetPx >= threshold -> -1
                                else -> 0
                            }
                            if (delta != 0) {
                                val next = Math.floorMod(selectedPosition + delta, options.size)
                                haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                                onSelect(options[next])
                            }
                            dragOffsetPx = 0f
                        },
                    )
                },
        ) {
            val widthPx = with(density) { maxWidth.toPx() }
            val itemSpacingPx = widthPx / 4.65f
            val centerX = widthPx / 2f
            val selected = options[selectedPosition]

            Canvas(modifier = Modifier.fillMaxSize()) {
                val inset = size.width * 0.08f
                drawArc(
                    color = Color.White.copy(alpha = if (loading) 0.055f else 0.10f),
                    startAngle = 198f,
                    sweepAngle = 144f,
                    useCenter = false,
                    topLeft = Offset(inset, -size.height * 0.14f),
                    size = Size(size.width - inset * 2f, size.height * 1.52f),
                    style = Stroke(width = 1.5f),
                )
            }

            options.forEachIndexed { position, location ->
                val relative = circularDistance(position, selectedPosition, options.size)
                if (kotlin.math.abs(relative) <= 2 || options.size <= 5) {
                    val visualRelative = relative + (dragOffsetPx / itemSpacingPx)
                    if (kotlin.math.abs(visualRelative) <= 2.45f) {
                        val depth = kotlin.math.abs(visualRelative).coerceAtMost(2f)
                        val bubbleSize = (58f - depth * 10f).dp
                        val y = (7f + depth * depth * 13f).dp
                        val bubbleSizePx = with(density) { bubbleSize.toPx() }
                        val xPx = centerX + (visualRelative * itemSpacingPx) - (bubbleSizePx / 2f)
                        val yPx = with(density) { y.toPx() }
                        val isFocused = kotlin.math.abs(visualRelative) < 0.48f

                        Box(
                            modifier = Modifier
                                .offset { IntOffset(xPx.roundToInt(), yPx.roundToInt()) }
                                .size(bubbleSize)
                                .graphicsLayer {
                                    alpha = (1f - depth * 0.23f).coerceIn(0.45f, 1f)
                                    scaleX = if (isFocused) 1.05f else 1f
                                    scaleY = if (isFocused) 1.05f else 1f
                                    shadowElevation = if (isFocused) 18f else 4f
                                }
                                .clip(CircleShape)
                                .background(
                                    if (isFocused) Color.White.copy(alpha = 0.18f)
                                    else Color.Black.copy(alpha = 0.13f),
                                )
                                .clickable(enabled = enabled) {
                                    if (location.index != selectedIndex) {
                                        haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                                        onSelect(location)
                                    }
                                },
                            contentAlignment = Alignment.Center,
                        ) {
                            Text(location.flag, fontSize = if (isFocused) 30.sp else 23.sp)
                        }
                    }
                }
            }

            Column(
                modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 1.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(
                    text = if (loading) "در حال دریافت لوکیشن‌ها…" else selected.name,
                    color = Color.White.copy(alpha = if (loading) 0.46f else 0.86f),
                    fontSize = 10.sp,
                    fontWeight = FontWeight.Bold,
                    maxLines = 1,
                )
                if (!loading && options.size > 1) {
                    Text(
                        "برای تغییر لوکیشن بکشید",
                        color = Color.White.copy(alpha = 0.34f),
                        fontSize = 8.sp,
                    )
                }
            }
        }
    }
}

private fun circularDistance(position: Int, selectedPosition: Int, count: Int): Int {
    if (count <= 1) return 0
    var distance = position - selectedPosition
    val half = count / 2
    if (distance > half) distance -= count
    if (distance < -half) distance += count
    return distance
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
    val stageMotion = rememberInfiniteTransition(label = "stageMotion")
    val floatY by stageMotion.animateFloat(
        initialValue = -3f,
        targetValue = 4f,
        animationSpec = infiniteRepeatable(
            animation = tween(2_700),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "stageFloatY",
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
            modifier = Modifier
                .size(globeSize)
                .offset(y = floatY.dp),
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
    val orbitPhase by pulseTransition.animateFloat(
        initialValue = 0f,
        targetValue = 360f,
        animationSpec = infiniteRepeatable(
            animation = tween(if (active) 8_500 else 14_000),
            repeatMode = RepeatMode.Restart,
        ),
        label = "globeOrbit",
    )

    Canvas(modifier = modifier) {
        val phaseRadians = Math.toRadians(orbitPhase.toDouble())
        val parallaxX = cos(phaseRadians).toFloat() * size.minDimension * 0.018f
        val parallaxY = sin(phaseRadians * 0.7).toFloat() * size.minDimension * 0.012f
        val center = Offset(
            size.width / 2f + parallaxX,
            size.height / 2f + parallaxY,
        )
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

        listOf(
            18f to 0.42f,
            -26f to 0.33f,
            63f to 0.26f,
        ).forEachIndexed { index, (angle, compression) ->
            rotate(
                degrees = angle + orbitPhase * (if (index % 2 == 0) 0.10f else -0.08f),
                pivot = center,
            ) {
                drawOval(
                    color = glow.copy(alpha = 0.045f + index * 0.012f),
                    topLeft = Offset(center.x - radius, center.y - radius * compression),
                    size = Size(radius * 2f, radius * compression * 2f),
                    style = Stroke(width = 1.25f),
                )
            }
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

        repeat(9) { index ->
            val radians = Math.toRadians(
                orbitPhase.toDouble() + (index * 40.0),
            )
            val depth = ((sin(radians) + 1.0) / 2.0).toFloat()
            val orbitX = cos(radians).toFloat() * radius * (0.88f - index * 0.018f)
            val orbitY = sin(radians).toFloat() * radius * 0.24f
            val particleRadius = 1.8f + depth * 3.2f
            drawCircle(
                color = glow.copy(
                    alpha = (if (active) 0.24f else 0.10f) + depth * 0.34f,
                ),
                radius = particleRadius,
                center = Offset(center.x + orbitX, center.y + orbitY),
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
    val localDensity = LocalDensity.current
    val haptic = LocalHapticFeedback.current
    val travelDp = 56.dp
    val travelPx = with(localDensity) { travelDp.toPx() }
    val topPaddingPx = with(localDensity) { 8.dp.toPx() }

    var dragDeltaPx by remember { mutableFloatStateOf(0f) }
    var dragging by remember { mutableStateOf(false) }
    var thresholdHapticSent by remember { mutableStateOf(false) }

    val stateTargetPx = when {
        connecting -> travelPx * 0.5f
        connected -> travelPx
        else -> 0f
    }
    val animatedBasePx by animateFloatAsState(
        targetValue = stateTargetPx,
        animationSpec = spring(
            dampingRatio = Spring.DampingRatioMediumBouncy,
            stiffness = Spring.StiffnessMediumLow,
        ),
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
                            thresholdHapticSent = false
                        },
                        onVerticalDrag = { change, dragAmount ->
                            change.consume()
                            dragDeltaPx += dragAmount
                            val candidate = (stateTargetPx + dragDeltaPx).coerceIn(0f, travelPx)
                            val thresholdReached = if (connected) {
                                candidate <= travelPx * 0.35f
                            } else {
                                candidate >= travelPx * 0.65f
                            }
                            if (thresholdReached && !thresholdHapticSent) {
                                thresholdHapticSent = true
                                haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                            }
                        },
                        onDragCancel = {
                            dragging = false
                            dragDeltaPx = 0f
                            thresholdHapticSent = false
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
                            thresholdHapticSent = false
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

            PowerThumbGlow(
                active = connected || dragging || connecting,
                modifier = Modifier
                    .offset {
                        IntOffset(
                            x = 0,
                            y = (topPaddingPx + visualOffsetPx - with(localDensity) { 5.dp.toPx() }).roundToInt(),
                        )
                    }
                    .align(Alignment.TopCenter)
                    .size(100.dp),
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
                    .graphicsLayer {
                        val fraction = if (travelPx > 0f) visualOffsetPx / travelPx else 0f
                        rotationX = (0.5f - fraction) * 8f
                        rotationY = if (dragging) dragDeltaPx.coerceIn(-20f, 20f) * 0.10f else 0f
                        scaleX = if (dragging) 1.04f else 1f
                        scaleY = if (dragging) 1.04f else 1f
                        shadowElevation = if (connected) 24f else 16f
                        cameraDistance = 20f * density
                    }
                    .clip(RoundedCornerShape(47.dp))
                    .background(Brush.verticalGradient(listOf(topColor, bottomColor))),
                contentAlignment = Alignment.Center,
            ) {
                PowerShimmer(active = connected || dragging || connecting)
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
    val animatedUsed by animateFloatAsState(
        targetValue = used.toFloat(),
        animationSpec = tween(900),
        label = "animatedUsedTraffic",
    )
    val animatedRemaining by animateFloatAsState(
        targetValue = remaining.toFloat(),
        animationSpec = tween(900),
        label = "animatedRemainingTraffic",
    )
    val localizedFallbackStatus = localizeServiceStatus(fallbackStatus)
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

    val dockMotion = rememberInfiniteTransition(label = "trafficDockMotion")
    val dockTilt by dockMotion.animateFloat(
        initialValue = -1.2f,
        targetValue = 1.2f,
        animationSpec = infiniteRepeatable(
            animation = tween(3_600),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "trafficDockTilt",
    )
    val dockFloat by dockMotion.animateFloat(
        initialValue = -1.5f,
        targetValue = 1.5f,
        animationSpec = infiniteRepeatable(
            animation = tween(2_800),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "trafficDockFloat",
    )

    Card(
        modifier = Modifier
            .fillMaxWidth()
            .graphicsLayer {
                translationY = dockFloat
                shadowElevation = 9f + kotlin.math.abs(dockTilt) * 2f
            },
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
                            else -> "باقی‌مانده: ${formatBytes(animatedRemaining.toLong())}"
                        },
                        fontWeight = FontWeight.Black,
                        fontSize = 14.sp,
                    )
                    Text(
                        when {
                            traffic == null -> localizedFallbackStatus.ifBlank { "آماده" }
                            unlimited -> "مصرف: ${formatBytes(animatedUsed.toLong())}"
                            else -> "مصرف: ${formatBytes(animatedUsed.toLong())} از ${formatBytes(total)}"
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
private fun PowerThumbGlow(
    active: Boolean,
    modifier: Modifier = Modifier,
) {
    val transition = rememberInfiniteTransition(label = "powerGlow")
    val pulse by transition.animateFloat(
        initialValue = 0.72f,
        targetValue = 1f,
        animationSpec = infiniteRepeatable(
            animation = tween(if (active) 1_050 else 2_200),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "powerGlowPulse",
    )
    Canvas(modifier = modifier) {
        val glowColor = if (active) Accent else Color.White
        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(
                    glowColor.copy(alpha = if (active) 0.18f * pulse else 0.05f),
                    glowColor.copy(alpha = if (active) 0.05f * pulse else 0.01f),
                    Color.Transparent,
                ),
                center = center,
                radius = size.minDimension * 0.62f,
            ),
            radius = size.minDimension * 0.62f,
            center = center,
        )
    }
}

@Composable
private fun PowerShimmer(
    active: Boolean,
    modifier: Modifier = Modifier.fillMaxSize(),
) {
    val transition = rememberInfiniteTransition(label = "powerShimmer")
    val phase by transition.animateFloat(
        initialValue = -0.35f,
        targetValue = 1.35f,
        animationSpec = infiniteRepeatable(
            animation = tween(if (active) 1_500 else 3_400),
            repeatMode = RepeatMode.Restart,
        ),
        label = "powerShimmerPhase",
    )
    Canvas(modifier = modifier) {
        val center = Offset(size.width * phase, size.height * 0.28f)
        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(
                    Color.White.copy(alpha = if (active) 0.20f else 0.08f),
                    Color.Transparent,
                ),
                center = center,
                radius = size.minDimension * 0.46f,
            ),
            radius = size.minDimension * 0.46f,
            center = center,
        )
    }
}

@Composable
private fun LivingBackground(
    active: Boolean,
    modifier: Modifier = Modifier.fillMaxSize(),
) {
    val transition = rememberInfiniteTransition(label = "livingBackground")
    val phase by transition.animateFloat(
        initialValue = 0f,
        targetValue = 1f,
        animationSpec = infiniteRepeatable(
            animation = tween(if (active) 12_000 else 18_000),
            repeatMode = RepeatMode.Restart,
        ),
        label = "livingBackgroundPhase",
    )

    Canvas(modifier = modifier) {
        repeat(18) { index ->
            val seed = index * 0.61803398875
            val baseX = ((seed % 1.0) * size.width).toFloat()
            val wave = sin(phase.toDouble() * 2.0 * PI + index * 0.73).toFloat()
            val x = (baseX + wave * size.width * 0.035f)
                .coerceIn(0f, size.width)
            val depth = 0.25f + ((index % 6) / 6f)
            val speed = 0.48f + depth * 0.92f
            val yCycle = (phase * speed + (index / 18f)) % 1f
            val y = size.height * (1.08f - yCycle * 1.16f)
            val radius = 1.1f + depth * 3.8f
            val particleColor = if (active) Accent else Color.White
            if (depth > 0.78f) {
                drawCircle(
                    color = particleColor.copy(alpha = 0.025f),
                    radius = radius * 2.6f,
                    center = Offset(x, y),
                )
            }
            drawCircle(
                color = particleColor.copy(
                    alpha = (0.02f + depth * if (active) 0.095f else 0.04f),
                ),
                radius = radius,
                center = Offset(x, y),
            )
        }

        val glowX = size.width * (0.25f + 0.5f * phase)
        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(
                    (if (active) Accent else Color(0xFF547080)).copy(alpha = 0.07f),
                    Color.Transparent,
                ),
                center = Offset(glowX, size.height * 0.34f),
                radius = size.minDimension * 0.75f,
            ),
            radius = size.minDimension * 0.75f,
            center = Offset(glowX, size.height * 0.34f),
        )
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
        Column(
            modifier = Modifier.fillMaxWidth().padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
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
                    Text("راهنما / دریافت", fontSize = 10.sp, fontWeight = FontWeight.Bold)
                }
            }

            if (info.releaseNotes.isNotBlank()) {
                Text(
                    text = info.releaseNotes,
                    color = Color.White.copy(alpha = 0.72f),
                    fontSize = 10.sp,
                )
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

private fun localizeServiceStatus(status: String): String {
    return when (status.trim().lowercase(Locale.US)) {
        "active", "enabled", "online", "ok" -> "فعال"
        "disabled", "inactive" -> "غیرفعال"
        "expired", "end_of_time" -> "منقضی شده"
        "end_of_volume", "limited" -> "حجم تمام شده"
        "sendedwarn" -> "نزدیک به پایان"
        "send_on_hold", "pending", "on_hold" -> "در انتظار"
        else -> status
    }
}

private fun formatElapsed(seconds: Long): String =
    PersianDateTime.formatDuration(seconds)

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

private fun formatExpiry(expiresAt: Long?): String =
    PersianDateTime.formatEpochSeconds(expiresAt, includeTime = true)
