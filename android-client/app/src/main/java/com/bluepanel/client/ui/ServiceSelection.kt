package com.bluepanel.client.ui

import com.bluepanel.client.data.ServiceSummary

internal fun selectServiceId(
    services: List<ServiceSummary>,
    selectedId: String?,
    connectedId: String?,
): String? = when {
    services.any { it.id == connectedId } -> connectedId
    services.any { it.id == selectedId } -> selectedId
    else -> services.firstOrNull()?.id
}
