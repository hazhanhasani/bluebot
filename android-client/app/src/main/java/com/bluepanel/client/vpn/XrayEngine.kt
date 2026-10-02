package com.bluepanel.client.vpn

import android.net.VpnService
import libXray.DialerController
import libXray.LibXray
import org.json.JSONArray
import org.json.JSONObject

/**
 * Thin, process-local bridge around the official libXray Android binding.
 * The app never persists decrypted subscription payloads or share links.
 */
class XrayEngine(private val vpnService: VpnService) {
    private val dialerController = object : DialerController {
        override fun protectFd(fd: Long): Boolean = vpnService.protect(fd.toInt())
    }

    fun start(tunFd: Int, sourceText: String) {
        require(tunFd >= 0) { "Invalid VPN interface" }
        require(sourceText.isNotBlank()) { "Empty connection profile" }

        LibXray.registerDialerController(dialerController)
        LibXray.registerListenerController(dialerController)
        LibXray.setDNS(dialerController, "1.1.1.1:53")

        val outbounds = parseOutbounds(sourceText)
        require(outbounds.length() > 0) { "No compatible Xray profile found" }

        val proxy = JSONObject(outbounds.getJSONObject(0).toString()).put("tag", "proxy")
        val config = JSONObject()
            .put("env", JSONObject().put("xray.tun.fd", tunFd.toString()))
            .put("log", JSONObject().put("loglevel", "warning"))
            .put(
                "dns",
                JSONObject().put("servers", JSONArray().put("1.1.1.1").put("8.8.8.8")),
            )
            .put(
                "inbounds",
                JSONArray().put(
                    JSONObject()
                        .put("tag", "tun")
                        .put("protocol", "tun")
                        .put(
                            "settings",
                            JSONObject()
                                .put("name", "blue0")
                                .put("mtu", 1500),
                        ),
                ),
            )
            .put(
                "outbounds",
                JSONArray()
                    .put(proxy)
                    .put(JSONObject().put("tag", "direct").put("protocol", "freedom"))
                    .put(JSONObject().put("tag", "block").put("protocol", "blackhole")),
            )
            .put(
                "routing",
                JSONObject()
                    .put("domainStrategy", "AsIs")
                    .put(
                        "rules",
                        JSONArray().put(
                            JSONObject()
                                .put("type", "field")
                                .put("inboundTag", JSONArray().put("tun"))
                                .put("outboundTag", "proxy"),
                        ),
                    ),
            )

        invokeChecked(
            JSONObject()
                .put("apiVersion", API_VERSION)
                .put("method", "runXray")
                .put("payload", JSONObject().put("xrayJson", config.toString())),
        )
    }

    fun stop() {
        runCatching {
            invokeChecked(
                JSONObject()
                    .put("apiVersion", API_VERSION)
                    .put("method", "stopXray")
                    .put("payload", JSONObject()),
            )
        }
        runCatching { LibXray.resetDNS() }
    }

    private fun parseOutbounds(sourceText: String): JSONArray {
        val response = invokeChecked(
            JSONObject()
                .put("apiVersion", API_VERSION)
                .put("method", "convertShareLinksToXrayJson")
                .put("payload", JSONObject().put("text", sourceText)),
        )
        val data = response.optJSONObject("data") ?: error("Xray parser returned no data")
        return data.optJSONArray("outbounds") ?: JSONArray()
    }

    private fun invokeChecked(request: JSONObject): JSONObject {
        val response = JSONObject(LibXray.invoke(request.toString()))
        if (!response.optBoolean("success", false)) {
            error(response.optString("error", "Xray operation failed"))
        }
        return response
    }

    private companion object {
        const val API_VERSION = 3
    }
}
