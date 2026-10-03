package com.bluepanel.client.data

data class LoginResult(
    val accessToken: String,
    val username: String,
)

data class ServiceSummary(
    val id: String,
    val username: String,
    val productName: String,
    val note: String,
    val status: String,
    val supported: Boolean,
)

data class TrafficInfo(
    val totalBytes: Long,
    val usedBytes: Long,
    val remainingBytes: Long,
)

data class ConnectionProfile(
    val serviceId: String,
    val username: String,
    val productName: String,
    val status: String,
    val traffic: TrafficInfo,
    val expiresAt: Long?,
    val links: List<String>,
    val subscriptionUrl: String?,
)

data class AppUpdateInfo(
    val latestVersionCode: Int,
    val latestVersionName: String,
    val minimumVersionCode: Int,
    val releaseTag: String,
    val downloadUrl: String,
    val releaseNotes: String,
    val checkIntervalSeconds: Long,
)


data class OtpRequestResult(
    val phone: String,
    val expiresIn: Int,
    val resendAfter: Int,
)

data class StorePlan(
    val id: String,
    val name: String,
    val description: String,
    val price: Int,
    val trafficGb: Int,
    val timeDays: Int,
    val category: String,
    val panelId: String,
    val panelName: String,
)

data class StoreCatalog(
    val products: List<StorePlan>,
    val gateways: List<String>,
    val balance: Int,
    val requiresAccount: Boolean,
)

data class CheckoutResult(
    val orderId: String,
    val invoiceId: String,
    val status: String,
    val paymentUrl: String?,
    val gateway: String,
)

data class OrderStatus(
    val orderId: String,
    val status: String,
    val gateway: String,
    val serviceId: String?,
)
