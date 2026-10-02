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

    suspend fun connectionText(profile: ConnectionProfile): String = withContext(Dispatchers.IO) {
        if (profile.links.isNotEmpty()) return@withContext profile.links.joinToString("\n")
        val url = profile.subscriptionUrl ?: error("No connection profile received")
        val uri = URI(url)
        require(uri.scheme.equals("https", ignoreCase = true)) { "Subscription URL must use HTTPS" }
        val connection = uri.toURL().openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "GET"
            connection.connectTimeout = 12_000
            connection.readTimeout = 15_000
            connection.setRequestProperty("User-Agent", "BluePanel-Android/${BuildConfig.VERSION_NAME}")
            if (connection.responseCode !in 200..299) error("Subscription server returned HTTP ${connection.responseCode}")
            connection.inputStream.bufferedReader().use { it.readText() }
        } finally {
            connection.disconnect()
        }
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
