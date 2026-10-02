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
