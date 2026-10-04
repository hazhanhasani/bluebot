package com.bluepanel.client.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Assert.assertThrows
import org.junit.Test

class ApiResponseTest {
    @Test
    fun expiredSessionUsesHttpStatusRegardlessOfMessageLanguage() {
        val error = assertThrows(ApiException::class.java) {
            parseApiResponse("""{"success":false,"message":"نشست منقضی شده است"}""", 401)
        }

        assertTrue(error.isAuthenticationFailure())
        assertEquals("نشست منقضی شده است", error.message)
    }

    @Test
    fun proxyUnauthorizedResponseAlsoExpiresSession() {
        val error = assertThrows(ApiException::class.java) {
            parseApiResponse("<html>Unauthorized</html>", 401)
        }

        assertTrue(error.isAuthenticationFailure())
    }

    @Test
    fun missingOrBlankLocalTokenRequiresLogin() {
        for (token in listOf(null, "", "  ")) {
            val error = assertThrows(ApiException::class.java) { requireSessionToken(token) }
            assertTrue(error.isAuthenticationFailure())
        }
        assertEquals("stored-token", requireSessionToken("stored-token"))
    }

    @Test
    fun otherHttpFailuresDoNotLogOutTheCustomer() {
        val error = assertThrows(ApiException::class.java) {
            parseApiResponse("""{"success":false,"message":"Panel unavailable"}""", 503)
        }

        assertEquals(503, error.statusCode)
        assertFalse(error.isAuthenticationFailure())
    }

    @Test
    fun httpFailureCannotMasqueradeAsSuccessfulJson() {
        val error = assertThrows(ApiException::class.java) {
            parseApiResponse("""{"success":true,"data":{"services":[]}}""", 500)
        }

        assertEquals(500, error.statusCode)
    }

    @Test
    fun successfulResponseReturnsTheDataEnvelope() {
        val data = parseApiResponse("""{"success":true,"data":{"service_id":"second-purchase"}}""", 200)

        assertEquals("second-purchase", data.getString("service_id"))
    }
}
