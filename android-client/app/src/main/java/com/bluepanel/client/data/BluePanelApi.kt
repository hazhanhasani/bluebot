package com.bluepanel.client.data

import com.bluepanel.client.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URI
import java.net.URLEncoder

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

    suspend fun serviceLocations(id: String): List<VpnLocation> = withContext(Dispatchers.IO) {
        val data = request(
            "locations",
            "GET",
            null,
            authenticated = true,
            params = mapOf("id" to id),
        )
        val rows = data.optJSONArray("locations") ?: JSONArray()
        buildList {
            add(VpnLocation(index = -1, name = "خودکار", flag = "🌐"))
            for (index in 0 until rows.length()) {
                val item = rows.getJSONObject(index)
                val locationIndex = item.optInt("index", -1)
                if (locationIndex < 0) continue
                add(
                    VpnLocation(
                        index = locationIndex,
                        name = item.optString("name", "لوکیشن").ifBlank { "لوکیشن" },
                        flag = item.optString("flag", "🌐").ifBlank { "🌐" },
                    ),
                )
            }
        }.distinctBy { it.index }
    }

    suspend fun connectionText(
        profile: ConnectionProfile,
        locationIndex: Int? = null,
    ): String = withContext(Dispatchers.IO) {
        val body = JSONObject()
            .put("action", "connection")
            .put("id", profile.serviceId)
        locationIndex
            ?.takeIf { it >= 0 }
            ?.let { body.put("location_index", it) }

        val data = request("connection", "POST", body, authenticated = true)
        data.optString("source")
            .takeIf { it.isNotBlank() && it != "null" }
            ?: error("No connection route received")
    }

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
                    val token = requireSessionToken(sessionStore.token())
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

                return parseApiResponse(raw, status)
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

internal fun requireSessionToken(token: String?): String =
    token?.takeIf { it.isNotBlank() } ?: throw ApiException(401, "Session expired")

internal fun parseApiResponse(raw: String, status: Int): JSONObject {
    val root = runCatching { JSONObject(raw) }.getOrElse {
        throw ApiException(
            status,
            if (status == 405) {
                "ورود توسط وب‌سرور با روش اشتباه دریافت شد (HTTP 405)."
            } else {
                "Invalid server response (HTTP $status)"
            },
        )
    }

    if (status !in 200..299 || !root.optBoolean("success", false)) {
        throw ApiException(status, root.optString("message", "Request failed"))
    }

    return root.optJSONObject("data") ?: JSONObject()
}
