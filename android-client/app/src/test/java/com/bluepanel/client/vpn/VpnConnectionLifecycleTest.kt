package com.bluepanel.client.vpn

import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.awaitCancellation
import kotlinx.coroutines.delay
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.withContext
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class VpnConnectionLifecycleTest {
    @Test
    fun disconnectDuringProfileFetchDoesNotReportConnectionFailure() = runTest {
        val errors = mutableListOf<Throwable>()
        var stopped = false
        var connected = false
        val fetch = CompletableDeferred<Unit>()
        val lifecycle = VpnConnectionLifecycle(this) {}

        lifecycle.connect(errors::add, prepare = { fetch.await() }) { _, _ ->
            connected = true
        }
        runCurrent()
        lifecycle.disconnect { stopped = true }
        advanceUntilIdle()
        fetch.complete(Unit)
        advanceUntilIdle()

        assertTrue(stopped)
        assertFalse(connected)
        assertTrue(errors.isEmpty())
    }

    @Test
    fun disconnectCompletesWhileCancelledBlockingPreparationIsStillPending() = runTest {
        val prepareGate = CompletableDeferred<Unit>()
        val errors = mutableListOf<Throwable>()
        var connected = false
        var stopped = false
        val lifecycle = VpnConnectionLifecycle(this) {}

        lifecycle.connect(
            errors::add,
            prepare = { withContext(NonCancellable) { prepareGate.await() } },
        ) { _, _ -> connected = true }
        runCurrent()
        lifecycle.disconnect { stopped = true }
        runCurrent()

        assertFalse(prepareGate.isCompleted)
        val stoppedBeforePreparationReturned = stopped
        prepareGate.complete(Unit)
        advanceUntilIdle()
        assertTrue(stoppedBeforePreparationReturned)
        assertFalse(connected)
        assertTrue(errors.isEmpty())
    }

    @Test
    fun replacementConnectsBeforeCancelledBlockingPreparationResolves() = runTest {
        val prepareGate = CompletableDeferred<Unit>()
        val errors = mutableListOf<Throwable>()
        val events = mutableListOf<String>()
        var activeTunnel: String? = null
        val lifecycle = VpnConnectionLifecycle(this) {
            events += "stop:$activeTunnel"
            activeTunnel = null
        }

        lifecycle.connect(
            errors::add,
            prepare = { withContext(NonCancellable) { prepareGate.await() }; "old" },
        ) { _, prepared ->
            activeTunnel = prepared
            events += "start:$prepared"
        }
        runCurrent()
        lifecycle.connect(errors::add, prepare = { "new" }) { _, prepared ->
            activeTunnel = prepared
            events += "start:$prepared"
        }
        runCurrent()

        assertFalse(prepareGate.isCompleted)
        val tunnelBeforePreparationReturned = activeTunnel
        prepareGate.complete(Unit)
        advanceUntilIdle()
        assertEquals("new", tunnelBeforePreparationReturned)
        assertEquals("new", activeTunnel)
        assertEquals("start:new", events.last())
        assertTrue(errors.isEmpty())
    }

    @Test
    fun supersededPreparationFailureCannotReportErrorOrStopNewTunnel() = runTest {
        val prepareGate = CompletableDeferred<Unit>()
        val errors = mutableListOf<Throwable>()
        var activeTunnel: String? = null
        val lifecycle = VpnConnectionLifecycle(this) { activeTunnel = null }

        lifecycle.connect(
            errors::add,
            prepare = {
                withContext(NonCancellable) {
                    prepareGate.await()
                    error("Old profile request failed")
                }
            },
        ) { _, _ -> activeTunnel = "old" }
        runCurrent()
        lifecycle.connect(errors::add) { activeTunnel = "new" }
        runCurrent()
        val tunnelBeforePreparationReturned = activeTunnel
        prepareGate.complete(Unit)
        advanceUntilIdle()

        assertEquals("new", tunnelBeforePreparationReturned)
        assertEquals("new", activeTunnel)
        assertTrue(errors.isEmpty())
    }

    @Test
    fun replacementWaitsForCancelledNativeStartThenKeepsNewTunnel() = runTest {
        val events = mutableListOf<String>()
        val errors = mutableListOf<Throwable>()
        var activeTunnel: String? = null
        val lifecycle = VpnConnectionLifecycle(this) {
            events += "stop:$activeTunnel"
            activeTunnel = null
        }

        lifecycle.connect(errors::add) {
            withContext(NonCancellable) {
                delay(100)
                activeTunnel = "old"
                events += "start:old"
            }
        }
        runCurrent()
        lifecycle.connect(errors::add) {
            activeTunnel = "new"
            events += "start:new"
        }
        advanceUntilIdle()

        assertEquals("new", activeTunnel)
        assertTrue(errors.isEmpty())
        assertTrue(events.indexOf("stop:old") < events.indexOf("start:new"))
        assertEquals("start:new", events.last())
    }

    @Test
    fun disconnectWaitsForNativeStartAndClosesItsTunnel() = runTest {
        var activeTunnel = false
        var stopped = false
        val errors = mutableListOf<Throwable>()
        val lifecycle = VpnConnectionLifecycle(this) { activeTunnel = false }

        lifecycle.connect(errors::add) {
            withContext(NonCancellable) {
                delay(100)
                activeTunnel = true
            }
        }
        runCurrent()
        lifecycle.disconnect {
            assertFalse(activeTunnel)
            stopped = true
        }
        advanceUntilIdle()

        assertTrue(stopped)
        assertFalse(activeTunnel)
        assertTrue(errors.isEmpty())
    }

    @Test
    fun supersededDisconnectCannotStopReplacementService() = runTest {
        var activeTunnel: String? = null
        var stopServiceCalls = 0
        val errors = mutableListOf<Throwable>()
        val lifecycle = VpnConnectionLifecycle(this) { activeTunnel = null }

        lifecycle.connect(errors::add) { awaitCancellation() }
        runCurrent()
        lifecycle.disconnect { stopServiceCalls++ }
        lifecycle.connect(errors::add) { activeTunnel = "new" }
        advanceUntilIdle()

        assertEquals("new", activeTunnel)
        assertEquals(0, stopServiceCalls)
        assertTrue(errors.isEmpty())
    }

    @Test
    fun disconnectSupersededDuringCleanupCannotStopReplacementService() = runTest {
        val cleanupGate = CompletableDeferred<Unit>()
        val errors = mutableListOf<Throwable>()
        var waitForCleanup = false
        var activeTunnel: String? = null
        var stopServiceCalls = 0
        val lifecycle = VpnConnectionLifecycle(this) {
            if (waitForCleanup) cleanupGate.await()
            activeTunnel = null
        }

        lifecycle.connect(errors::add) { activeTunnel = "old" }
        runCurrent()
        waitForCleanup = true
        lifecycle.disconnect { stopServiceCalls++ }
        runCurrent()
        lifecycle.connect(errors::add) { activeTunnel = "new" }
        runCurrent()
        cleanupGate.complete(Unit)
        advanceUntilIdle()

        assertEquals("new", activeTunnel)
        assertEquals(0, stopServiceCalls)
        assertTrue(errors.isEmpty())
    }

    @Test
    fun failureClosesTunnelBeforeReportingItsOriginalCause() = runTest {
        val expected = IllegalStateException("Unable to start Xray")
        var activeTunnel = false
        var reported: Throwable? = null
        val lifecycle = VpnConnectionLifecycle(this) { activeTunnel = false }

        lifecycle.connect(
            onError = {
                assertFalse(activeTunnel)
                reported = it
            },
        ) {
            activeTunnel = true
            throw expected
        }
        advanceUntilIdle()

        assertSame(expected, reported)
        assertFalse(activeTunnel)
    }

    @Test
    fun replacingSameServiceInvalidatesPreviousTrafficMonitor() = runTest {
        var oldAttempt = 0L
        var newAttempt = 0L
        val lifecycle = VpnConnectionLifecycle(this) {}
        lifecycle.connect(onError = { throw it }) { oldAttempt = it }
        advanceUntilIdle()
        assertTrue(lifecycle.isCurrent(oldAttempt))

        lifecycle.connect(onError = { throw it }) { newAttempt = it }
        advanceUntilIdle()

        assertFalse(lifecycle.isCurrent(oldAttempt))
        assertTrue(lifecycle.isCurrent(newAttempt))
    }

    @Test
    fun stoppingServicePreservesConnectionAndAuthenticationErrors() {
        val connectionError = VpnConnectionState.Error("Unable to start Xray")
        val error = VpnConnectionState.Error("Session expired", authenticationRequired = true)
        assertSame(connectionError, connectionError.afterServiceStopped())
        assertSame(error, error.afterServiceStopped())
        assertTrue((error.afterServiceStopped() as VpnConnectionState.Error).authenticationRequired)
        assertSame(VpnConnectionState.Disconnected, VpnConnectionState.Connecting.afterServiceStopped())
    }
}
