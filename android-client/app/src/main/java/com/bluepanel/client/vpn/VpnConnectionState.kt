package com.bluepanel.client.vpn

import com.bluepanel.client.data.TrafficInfo

sealed interface VpnConnectionState {
    data object Disconnected : VpnConnectionState
    data object Connecting : VpnConnectionState
    data class Connected(
        val serviceId: String,
        val locationIndex: Int?,
        val productName: String,
        val username: String,
        val traffic: TrafficInfo,
        val expiresAt: Long?,
        val connectedAtElapsedRealtime: Long,
    ) : VpnConnectionState
    data class Error(
        val message: String,
        val authenticationRequired: Boolean = false,
    ) : VpnConnectionState

    /** Keep the failure visible after Android destroys the stopped service. */
    fun afterServiceStopped(): VpnConnectionState = if (this is Error) this else Disconnected
}
