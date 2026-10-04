package com.bluepanel.client.vpn

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext

/** Commands are issued on the service's main dispatcher; tunnel operations share one lock. */
internal class VpnConnectionLifecycle(
    private val scope: CoroutineScope,
    private val stopTunnel: suspend () -> Unit,
) {
    private val tunnelLock = Mutex()
    private var attempt = 0L
    private var connectionJob: Job? = null

    fun isCurrent(value: Long): Boolean = value == attempt

    fun connect(
        onError: (Throwable) -> Unit,
        startTunnel: suspend (Long) -> Unit,
    ) = connect(onError, prepare = { Unit }) { currentAttempt, _ ->
        startTunnel(currentAttempt)
    }

    fun <T> connect(
        onError: (Throwable) -> Unit,
        prepare: suspend () -> T,
        startTunnel: suspend (Long, T) -> Unit,
    ) {
        val currentAttempt = ++attempt
        connectionJob?.cancel()
        connectionJob = scope.launch {
            try {
                tunnelLock.withLock {
                    currentCoroutineContext().ensureActive()
                    cleanup()
                    currentCoroutineContext().ensureActive()
                }
                // Blocking network preparation must not delay a disconnect or replacement.
                val prepared = prepare()
                currentCoroutineContext().ensureActive()
                tunnelLock.withLock {
                    currentCoroutineContext().ensureActive()
                    try {
                        startTunnel(currentAttempt, prepared)
                        currentCoroutineContext().ensureActive()
                    } catch (error: Throwable) {
                        // A native start may finish despite cancellation. Close it before
                        // a newer attempt can acquire the lock and establish its tunnel.
                        cleanup()
                        throw error
                    }
                }
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (error: Throwable) {
                if (isCurrent(currentAttempt)) onError(error)
            }
        }
    }

    fun disconnect(onStopped: () -> Unit) {
        val currentAttempt = ++attempt
        connectionJob?.cancel()
        connectionJob = null
        scope.launch {
            tunnelLock.withLock {
                if (isCurrent(currentAttempt)) {
                    cleanup()
                    if (isCurrent(currentAttempt)) onStopped()
                }
            }
        }
    }

    private suspend fun cleanup() = withContext(NonCancellable) {
        stopTunnel()
    }
}
