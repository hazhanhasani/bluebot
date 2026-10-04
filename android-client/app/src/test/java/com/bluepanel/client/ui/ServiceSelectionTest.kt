package com.bluepanel.client.ui

import com.bluepanel.client.data.ServiceSummary
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class ServiceSelectionTest {
    private val first = service("first-purchase")
    private val second = service("second-purchase")

    @Test
    fun manuallySelectedSecondPurchaseSurvivesRefreshAndReordering() {
        assertEquals(second.id, selectServiceId(listOf(first, second), second.id, null))
        assertEquals(second.id, selectServiceId(listOf(second, first), second.id, null))
    }

    @Test
    fun activeVpnKeepsItsServiceSelected() {
        assertEquals(first.id, selectServiceId(listOf(first, second), second.id, first.id))
    }

    @Test
    fun disconnectRetainsThePreviouslyActiveService() {
        assertEquals(second.id, selectServiceId(listOf(first, second), second.id, null))
    }

    @Test
    fun removedServiceFallsBackToAnotherPurchaseAndEmptyAccountClearsSelection() {
        assertEquals(first.id, selectServiceId(listOf(first), second.id, null))
        assertNull(selectServiceId(emptyList(), second.id, second.id))
    }

    private fun service(id: String) = ServiceSummary(
        id = id,
        username = "vpn-$id",
        productName = "Plan $id",
        note = "",
        status = "active",
        supported = true,
    )
}
