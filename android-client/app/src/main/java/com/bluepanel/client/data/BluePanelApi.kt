package com.bluepanel.client.data

import android.util.Base64
import com.bluepanel.client.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URI
import java.net.URLDecoder
import java.net.URLEncoder
import java.nio.charset.StandardCharsets
import java.util.Locale

class BluePanelApi(private val sessionStore: SessionStore) {
    suspend fun login(username: String, password: String): LoginResult = withContext(Dispatchers.IO) {
        val body = JSONObject()
            .put("action", "login")
            .put("username", username.trim())
            .put("password", password)
            .put("device_id", sessionStore.deviceId)
        val data = request("login", "POST", body, authenticated = false)
        val token = data.getString("access_token")
        val account = data.getJSONObject("account")
        LoginResult(token, account.getString("username"))
    }

    suspend fun qrLogin(payload: String): LoginResult = withContext(Dispatchers.IO) {
        val body = JSONObject()
            .put("action", "qr-login")
            .put("qr_payload", payload.trim())
            .put("device_id", sessionStore.deviceId)
        val data = request("qr-login", "POST", body, authenticated = false)
        val token = data.getString("access_token")
        val account = data.getJSONObject("account")
        LoginResult(token, account.getString("username"))
    }

    suspend fun requestMobileOtp(phone: String): OtpRequestResult = withContext(Dispatchers.IO) {
        val body = JSONObject()
            .put("action", "mobile-otp-request")
            .put("phone", phone.trim())
            .put("device_id", sessionStore.deviceId)
        val data = request("mobile-otp-request", "POST", body, authenticated = false)
        OtpRequestResult(
            phone = data.optString("phone"),
            expiresIn = data.optInt("expires_in", 120),
            resendAfter = data.optInt("resend_after", 60),
        )
    }

    suspend fun verifyMobileOtp(phone: String, code: String): LoginResult = withContext(Dispatchers.IO) {
        val body = JSONObject()
            .put("action", "mobile-otp-verify")
            .put("phone", phone.trim())
            .put("code", code.trim())
            .put("device_id", sessionStore.deviceId)
        val data = request("mobile-otp-verify", "POST", body, authenticated = false)
        val token = data.getString("access_token")
        val account = data.getJSONObject("account")
        LoginResult(token, account.optString("username", account.optString("phone")))
    }

    suspend fun storeCatalog(): StoreCatalog = withContext(Dispatchers.IO) {
        val data = request("store-catalog", "GET", null, authenticated = true)
        val rows = data.optJSONArray("products") ?: JSONArray()
        val products = buildList {
            for (index in 0 until rows.length()) {
                val item = rows.getJSONObject(index)
                add(
                    StorePlan(
                        id = item.optString("id"),
                        name = item.optString("name"),
                        description = item.optString("description"),
                        price = item.optInt("price"),
                        trafficGb = item.optInt("traffic_gb"),
                        timeDays = item.optInt("time_days"),
                        category = item.optString("category"),
                        panelId = item.optString("panel_id"),
                        panelName = item.optString("panel_name"),
                    ),
                )
            }
        }
        val gatewaysJson = data.optJSONArray("gateways") ?: JSONArray()
        val gateways = buildList {
            for (index in 0 until gatewaysJson.length()) {
                gatewaysJson.optString(index).takeIf { it.isNotBlank() }?.let(::add)
            }
        }
        StoreCatalog(
            products = products,
            gateways = gateways,
            balance = data.optInt("balance"),
            requiresAccount = data.optBoolean("requires_account", false),
        )
    }

    suspend fun checkout(
        productId: String,
        panelId: String,
        gateway: String,
    ): CheckoutResult = withContext(Dispatchers.IO) {
        val body = JSONObject()
            .put("action", "store-checkout")
            .put("product_id", productId)
            .put("panel_id", panelId)
            .put("gateway", gateway)
        val data = request("store-checkout", "POST", body, authenticated = true)
        CheckoutResult(
            orderId = data.optString("order_id"),
            invoiceId = data.optString("invoice_id"),
            status = data.optString("status"),
            paymentUrl = data.optString("payment_url")
                .takeIf { it.isNotBlank() && it != "null" },
            gateway = data.optString("gateway"),
        )
    }

    suspend fun orderStatus(orderId: String): OrderStatus = withContext(Dispatchers.IO) {
        val data = request(
            "store-order-status",
            "GET",
            null,
            authenticated = true,
            params = mapOf("order_id" to orderId),
        )
        val service = data.optJSONObject("service")
        OrderStatus(
            orderId = data.optString("order_id"),
            status = data.optString("status"),
            gateway = data.optString("gateway"),
            serviceId = service?.optString("id")?.takeIf { it.isNotBlank() },
        )
    }

    suspend fun updateInfo(): AppUpdateInfo = withContext(Dispatchers.IO) {
        val data = request("app-version", "GET", null, authenticated = false)
        val android = data.getJSONObject("android")
        AppUpdateInfo(
            latestVersionCode = android.optInt("latest_version_code", 1),
            latestVersionName = android.optString("latest_version_name", "0.1.0"),
            minimumVersionCode = android.optInt("minimum_version_code", 1),
            releaseTag = android.optString("release_tag"),
            downloadUrl = android.getString("download_url"),
            releaseNotes = android.optString("release_notes"),
            checkIntervalSeconds = android.optLong("check_interval_seconds", 21_600L)
                .coerceAtLeast(3_600L),
        )
    }

    suspend fun services(): List<ServiceSummary> = withContext(Dispatchers.IO) {
        val data = request("services", "GET", null, authenticated = true)
        val rows = data.optJSONArray("services") ?: JSONArray()
        buildList {
            for (index in 0 until rows.length()) {
                val item = rows.getJSONObject(index)
                add(
                    ServiceSummary(
                        id = item.optString("id"),
                        username = item.optString("username"),
                        productName = item.optString("product_name"),
                        note = item.optString("note"),
                        status = item.optString("status"),
                        supported = item.optBoolean("supported", false),
                    ),
                )
            }
        }
    }

    suspend fun service(id: String): ConnectionProfile = withContext(Dispatchers.IO) {
        val data = request("service", "GET", null, authenticated = true, params = mapOf("id" to id))
        val service = data.getJSONObject("service")
        val traffic = service.getJSONObject("traffic")
        val connection = data.getJSONObject("connection")
        val linksJson = connection.optJSONArray("links") ?: JSONArray()
        val links = buildList {
            for (index in 0 until linksJson.length()) add(linksJson.getString(index))
        }
        ConnectionProfile(
            serviceId = service.getString("id"),
            username = service.optString("username"),
            productName = service.optString("product_name"),
            status = service.optString("status"),
            traffic = TrafficInfo(
                totalBytes = traffic.optLong("total_bytes"),
                usedBytes = traffic.optLong("used_bytes"),
                remainingBytes = traffic.optLong("remaining_bytes"),
            ),
            expiresAt = if (service.isNull("expires_at")) null else service.optLong("expires_at"),
            links = links,
            subscriptionUrl = connection.optString("subscription_url").takeIf { it.isNotBlank() && it != "null" },
        )
    }

    suspend fun logout() = withContext(Dispatchers.IO) {
        runCatching {
            request(
                action = "logout",
                method = "POST",
                body = JSONObject().put("action", "logout"),
                authenticated = true,
            )
        }
        sessionStore.clear()
    }

    suspend fun serviceLocations(id: String): List<VpnLocation> {
        val profile = service(id)
        val sourceText = connectionText(profile)
        val locations = extractShareLinks(sourceText)
            .mapIndexed { index, link -> locationFromLink(index, link) }
            .distinctBy { "${it.flag}|${it.name.lowercase(Locale.ROOT)}" }

        return locations.ifEmpty {
            listOf(VpnLocation(index = -1, name = "خودکار", flag = "🌐"))
        }
    }

    suspend fun connectionText(
        profile: ConnectionProfile,
        locationIndex: Int? = null,
    ): String = withContext(Dispatchers.IO) {
        val sourceText = if (profile.links.isNotEmpty()) {
            profile.links.joinToString("\n")
        } else {
            val url = profile.subscriptionUrl ?: error("No connection profile received")
            val uri = URI(url)
            require(uri.scheme.equals("https", ignoreCase = true)) {
                "Subscription URL must use HTTPS"
            }
            val connection = uri.toURL().openConnection() as HttpURLConnection
            try {
                connection.requestMethod = "GET"
                connection.connectTimeout = 12_000
                connection.readTimeout = 15_000
                connection.setRequestProperty(
                    "User-Agent",
                    "BluePanel-Android/${BuildConfig.VERSION_NAME}",
                )
                if (connection.responseCode !in 200..299) {
                    error("Subscription server returned HTTP ${connection.responseCode}")
                }
                connection.inputStream.bufferedReader().use { it.readText() }
            } finally {
                connection.disconnect()
            }
        }

        val links = extractShareLinks(sourceText)
        locationIndex?.takeIf { it >= 0 }?.let { index ->
            links.getOrNull(index)?.let { return@withContext it }
        }
        sourceText
    }

    private fun extractShareLinks(sourceText: String): List<String> {
        fun scan(text: String): List<String> = text
            .lineSequence()
            .flatMap { it.trim().split(Regex("\\s+")).asSequence() }
            .map { it.trim() }
            .filter { token ->
                token.matches(
                    Regex(
                        "^(vless|vmess|trojan|ss|socks|hysteria2|hy2)://.+",
                        RegexOption.IGNORE_CASE,
                    ),
                )
            }
            .toList()

        val direct = scan(sourceText)
        if (direct.isNotEmpty()) return direct

        val compact = sourceText.filterNot(Char::isWhitespace)
        val decoded = decodeBase64Text(compact) ?: return emptyList()
        return scan(decoded)
    }

    private fun decodeBase64Text(raw: String): String? {
        if (raw.isBlank()) return null
        val normalized = raw.trim().replace('-', '+').replace('_', '/')
        val padded = normalized + "=".repeat((4 - (normalized.length % 4)) % 4)
        return runCatching {
            String(Base64.decode(padded, Base64.DEFAULT), Charsets.UTF_8)
        }.getOrNull()
    }

    private fun locationFromLink(index: Int, link: String): VpnLocation {
        val rawLabel = when {
            link.startsWith("vmess://", ignoreCase = true) -> {
                val encoded = link.substringAfter("://").substringBefore('#').trim()
                decodeBase64Text(encoded)?.let { payload ->
                    runCatching { JSONObject(payload).optString("ps") }.getOrNull()
                }.orEmpty()
            }
            else -> runCatching {
                URI(link).rawFragment
                    ?.let { URLDecoder.decode(it, StandardCharsets.UTF_8.name()) }
                    .orEmpty()
            }.getOrDefault("")
        }.trim()

        val flag = extractRegionalFlag(rawLabel)
            ?: inferCountryFlag(rawLabel)
            ?: "🌐"
        val cleanName = rawLabel
            .replace(flag, "")
            .trim()
            .trim('-', '_', '|', '•')
            .trim()
            .ifBlank { localizedCountryName(flag) ?: "لوکیشن ${index + 1}" }
            .take(36)

        return VpnLocation(index = index, name = cleanName, flag = flag)
    }

    private fun extractRegionalFlag(text: String): String? {
        val points = text.codePoints().toArray()
        for (index in 0 until points.size - 1) {
            val first = points[index]
            val second = points[index + 1]
            if (first in 0x1F1E6..0x1F1FF && second in 0x1F1E6..0x1F1FF) {
                return buildString {
                    append(String(Character.toChars(first)))
                    append(String(Character.toChars(second)))
                }
            }
        }
        return null
    }

    private fun inferCountryFlag(label: String): String? {
        val normalized = label.lowercase(Locale.ROOT)
        val hints = listOf(
            listOf("germany", "deutschland", "frankfurt", "آلمان") to "🇩🇪",
            listOf("netherlands", "holland", "amsterdam", "هلند") to "🇳🇱",
            listOf("finland", "helsinki", "فنلاند") to "🇫🇮",
            listOf("france", "paris", "فرانسه") to "🇫🇷",
            listOf("united kingdom", "england", "london", "britain", "انگلیس") to "🇬🇧",
            listOf("united states", "america", "new york", "los angeles", "usa", "آمریکا") to "🇺🇸",
            listOf("canada", "toronto", "montreal", "کانادا") to "🇨🇦",
            listOf("turkey", "turkiye", "istanbul", "ترکیه") to "🇹🇷",
            listOf("sweden", "stockholm", "سوئد") to "🇸🇪",
            listOf("switzerland", "zurich", "سوئیس") to "🇨🇭",
            listOf("poland", "warsaw", "لهستان") to "🇵🇱",
            listOf("romania", "bucharest", "رومانی") to "🇷🇴",
            listOf("russia", "moscow", "روسیه") to "🇷🇺",
            listOf("united arab emirates", "dubai", "uae", "امارات") to "🇦🇪",
            listOf("india", "mumbai", "هند") to "🇮🇳",
            listOf("singapore", "سنگاپور") to "🇸🇬",
            listOf("japan", "tokyo", "ژاپن") to "🇯🇵",
            listOf("south korea", "korea", "seoul", "کره") to "🇰🇷",
            listOf("hong kong", "هنگ کنگ") to "🇭🇰",
            listOf("australia", "sydney", "استرالیا") to "🇦🇺",
            listOf("armenia", "yerevan", "ارمنستان") to "🇦🇲",
            listOf("azerbaijan", "baku", "آذربایجان") to "🇦🇿",
            listOf("iran", "tehran", "ایران") to "🇮🇷",
            listOf("iraq", "baghdad", "عراق") to "🇮🇶",
            listOf("qatar", "doha", "قطر") to "🇶🇦",
        )
        hints.firstOrNull { (terms, _) -> terms.any { term -> normalized.contains(term) } }
            ?.let { return it.second }

        val codeFlags = mapOf(
            "DE" to "🇩🇪", "NL" to "🇳🇱", "FI" to "🇫🇮", "FR" to "🇫🇷",
            "GB" to "🇬🇧", "UK" to "🇬🇧", "US" to "🇺🇸", "CA" to "🇨🇦",
            "TR" to "🇹🇷", "SE" to "🇸🇪", "CH" to "🇨🇭", "PL" to "🇵🇱",
            "RO" to "🇷🇴", "RU" to "🇷🇺", "AE" to "🇦🇪", "IN" to "🇮🇳",
            "SG" to "🇸🇬", "JP" to "🇯🇵", "KR" to "🇰🇷", "HK" to "🇭🇰",
            "AU" to "🇦🇺", "AM" to "🇦🇲", "AZ" to "🇦🇿", "IR" to "🇮🇷",
            "IQ" to "🇮🇶", "QA" to "🇶🇦",
        )
        return Regex("(^|[^A-Za-z])([A-Za-z]{2})(?=$|[^A-Za-z])")
            .findAll(label)
            .map { it.groupValues[2].uppercase(Locale.ROOT) }
            .mapNotNull(codeFlags::get)
            .firstOrNull()
    }

    private fun localizedCountryName(flag: String): String? = mapOf(
        "🇩🇪" to "آلمان", "🇳🇱" to "هلند", "🇫🇮" to "فنلاند", "🇫🇷" to "فرانسه",
        "🇬🇧" to "انگلیس", "🇺🇸" to "آمریکا", "🇨🇦" to "کانادا", "🇹🇷" to "ترکیه",
        "🇸🇪" to "سوئد", "🇨🇭" to "سوئیس", "🇵🇱" to "لهستان", "🇷🇴" to "رومانی",
        "🇷🇺" to "روسیه", "🇦🇪" to "امارات", "🇮🇳" to "هند", "🇸🇬" to "سنگاپور",
        "🇯🇵" to "ژاپن", "🇰🇷" to "کره جنوبی", "🇭🇰" to "هنگ‌کنگ", "🇦🇺" to "استرالیا",
        "🇦🇲" to "ارمنستان", "🇦🇿" to "آذربایجان", "🇮🇷" to "ایران", "🇮🇶" to "عراق",
        "🇶🇦" to "قطر",
    )[flag]

    private fun request(
        action: String,
        method: String,
        body: JSONObject?,
        authenticated: Boolean,
        params: Map<String, String> = emptyMap(),
    ): JSONObject {
        val normalizedMethod = method.uppercase()
        val requestBody = if (normalizedMethod == "POST") {
            JSONObject((body ?: JSONObject()).toString()).apply {
                if (!has("action")) put("action", action)
            }
        } else {
            body
        }

        val query = buildList {
            if (normalizedMethod != "POST") add("action=${encode(action)}")
            params.forEach { (key, value) -> add("${encode(key)}=${encode(value)}") }
        }.joinToString("&")

        val initialUri = if (query.isBlank()) {
            URI(BuildConfig.BLUEBOT_API_BASE)
        } else {
            val separator = if (BuildConfig.BLUEBOT_API_BASE.contains('?')) '&' else '?'
            URI(BuildConfig.BLUEBOT_API_BASE + separator + query)
        }

        return executeJsonRequest(
            initialUri = initialUri,
            method = normalizedMethod,
            body = requestBody,
            authenticated = authenticated,
        )
    }

    private fun executeJsonRequest(
        initialUri: URI,
        method: String,
        body: JSONObject?,
        authenticated: Boolean,
    ): JSONObject {
        val apiOrigin = URI(BuildConfig.BLUEBOT_API_BASE)
        var currentUri = initialUri

        repeat(MAX_REDIRECTS + 1) { attempt ->
            val connection = currentUri.toURL().openConnection() as HttpURLConnection
            try {
                connection.instanceFollowRedirects = false
                connection.requestMethod = method
                connection.connectTimeout = 12_000
                connection.readTimeout = 15_000
                connection.setRequestProperty("Accept", "application/json")
                connection.setRequestProperty("User-Agent", "BluePanel-Android/${BuildConfig.VERSION_NAME}")
                connection.setRequestProperty("X-BluePanel-Client", "android")
                connection.setRequestProperty("X-Device-Id", sessionStore.deviceId)

                if (authenticated) {
                    val token = sessionStore.token() ?: error("Session expired")
                    connection.setRequestProperty("Authorization", "Bearer $token")
                }

                if (body != null) {
                    connection.doOutput = true
                    connection.setRequestProperty("Content-Type", "application/json; charset=utf-8")
                    connection.outputStream.use {
                        it.write(body.toString().toByteArray(Charsets.UTF_8))
                    }
                }

                val status = connection.responseCode

                if (status in REDIRECT_CODES) {
                    if (attempt >= MAX_REDIRECTS) error("Too many API redirects")
                    val location = connection.getHeaderField("Location")
                        ?.trim()
                        ?.takeIf { it.isNotEmpty() }
                        ?: error("API redirect did not provide a Location header")

                    val nextUri = currentUri.resolve(location)
                    validateRedirect(apiOrigin, nextUri)
                    currentUri = nextUri
                    return@repeat
                }

                val raw = (if (status in 200..299) connection.inputStream else connection.errorStream)
                    ?.bufferedReader()
                    ?.use { it.readText() }
                    .orEmpty()

                val root = runCatching { JSONObject(raw) }
                    .getOrElse {
                        error(
                            if (status == 405) {
                                "ورود توسط وب‌سرور با روش اشتباه دریافت شد (HTTP 405)."
                            } else {
                                "Invalid server response (HTTP $status)"
                            },
                        )
                    }

                if (!root.optBoolean("success", false)) {
                    error(root.optString("message", "Request failed"))
                }

                return root.optJSONObject("data") ?: JSONObject()
            } finally {
                connection.disconnect()
            }
        }

        error("API request failed")
    }

    private fun validateRedirect(apiOrigin: URI, target: URI) {
        require(target.scheme.equals("https", ignoreCase = true)) {
            "Unsafe API redirect blocked"
        }
        require(target.host.equals(apiOrigin.host, ignoreCase = true)) {
            "Cross-domain API redirect blocked"
        }
    }

    private fun encode(value: String): String = URLEncoder.encode(value, Charsets.UTF_8.name())

    private companion object {
        const val MAX_REDIRECTS = 3
        val REDIRECT_CODES = setOf(
            HttpURLConnection.HTTP_MOVED_PERM,
            HttpURLConnection.HTTP_MOVED_TEMP,
            HttpURLConnection.HTTP_SEE_OTHER,
            307,
            308,
        )
    }
}
