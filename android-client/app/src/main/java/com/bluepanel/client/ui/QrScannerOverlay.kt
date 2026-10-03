package com.bluepanel.client.ui

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean

private val ScannerAccent = Color(0xFF41D6DE)

@Composable
fun QrScannerOverlay(
    onResult: (String) -> Unit,
    onClose: () -> Unit,
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    var permissionGranted by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) ==
                PackageManager.PERMISSION_GRANTED,
        )
    }
    var permissionRequested by remember { mutableStateOf(false) }
    var cameraProvider by remember { mutableStateOf<ProcessCameraProvider?>(null) }
    var galleryBusy by remember { mutableStateOf(false) }
    var scanError by remember { mutableStateOf<String?>(null) }

    val analyzerExecutor = remember { Executors.newSingleThreadExecutor() }
    val consumed = remember { AtomicBoolean(false) }
    val scanner = remember {
        val options = BarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_QR_CODE)
            .build()
        BarcodeScanning.getClient(options)
    }

    val galleryLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.GetContent(),
    ) { uri ->
        if (uri == null) {
            return@rememberLauncherForActivityResult
        }

        galleryBusy = true
        scanError = null
        consumed.set(false)

        val input = runCatching {
            InputImage.fromFilePath(context, uri)
        }.getOrElse {
            galleryBusy = false
            scanError = "خواندن تصویر انتخاب‌شده ممکن نشد."
            return@rememberLauncherForActivityResult
        }

        scanner.process(input)
            .addOnSuccessListener { barcodes ->
                val raw = barcodes
                    .firstOrNull { !it.rawValue.isNullOrBlank() }
                    ?.rawValue
                    ?.trim()
                    .orEmpty()

                if (raw.isNotEmpty() && consumed.compareAndSet(false, true)) {
                    onResult(raw)
                } else if (raw.isEmpty()) {
                    scanError = "QR معتبری داخل این تصویر پیدا نشد."
                }
            }
            .addOnFailureListener {
                scanError = "تشخیص QR از تصویر ناموفق بود."
            }
            .addOnCompleteListener {
                galleryBusy = false
            }
    }

    val permissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        permissionGranted = granted
        permissionRequested = true
    }

    LaunchedEffect(Unit) {
        if (!permissionGranted) {
            permissionRequested = true
            permissionLauncher.launch(Manifest.permission.CAMERA)
        }
    }

    DisposableEffect(Unit) {
        onDispose {
            cameraProvider?.unbindAll()
            scanner.close()
            analyzerExecutor.shutdown()
        }
    }

    Dialog(
        onDismissRequest = onClose,
        properties = DialogProperties(
            usePlatformDefaultWidth = false,
            decorFitsSystemWindows = false,
        ),
    ) {
        Surface(
            modifier = Modifier.fillMaxSize(),
            color = Color(0xFF05080D),
        ) {
            if (!permissionGranted) {
                Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(28.dp),
                    verticalArrangement = Arrangement.Center,
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Text(
                        "برای اسکن QR سرویس، دسترسی دوربین لازم است.",
                        color = Color.White,
                        fontSize = 16.sp,
                    )
                    Spacer(Modifier.height(18.dp))
                    if (permissionRequested) {
                        Button(
                            onClick = {
                                permissionLauncher.launch(Manifest.permission.CAMERA)
                            },
                            colors = ButtonDefaults.buttonColors(containerColor = ScannerAccent),
                            shape = RoundedCornerShape(16.dp),
                        ) {
                            Text("اجازه دسترسی به دوربین", color = Color(0xFF031719))
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                    Button(
                        onClick = { galleryLauncher.launch("image/*") },
                        enabled = !galleryBusy,
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF17242B)),
                        shape = RoundedCornerShape(16.dp),
                    ) {
                        Text(if (galleryBusy) "در حال بررسی تصویر…" else "انتخاب QR از گالری")
                    }
                    if (!scanError.isNullOrBlank()) {
                        Spacer(Modifier.height(10.dp))
                        Text(
                            scanError.orEmpty(),
                            color = Color(0xFFFF9B92),
                            fontSize = 12.sp,
                        )
                    }
                    Spacer(Modifier.height(10.dp))
                    Button(
                        onClick = onClose,
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF242A32)),
                        shape = RoundedCornerShape(16.dp),
                    ) {
                        Text("بازگشت")
                    }
                }
            } else {
                Box(modifier = Modifier.fillMaxSize()) {
                    AndroidView(
                        modifier = Modifier.fillMaxSize(),
                        factory = { ctx ->
                            PreviewView(ctx).apply {
                                implementationMode = PreviewView.ImplementationMode.COMPATIBLE
                                scaleType = PreviewView.ScaleType.FILL_CENTER

                                val providerFuture = ProcessCameraProvider.getInstance(ctx)
                                providerFuture.addListener({
                                    val provider = providerFuture.get()
                                    cameraProvider = provider

                                    val preview = Preview.Builder().build().also {
                                        it.setSurfaceProvider(surfaceProvider)
                                    }

                                    val analysis = ImageAnalysis.Builder()
                                        .setBackpressureStrategy(
                                            ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST,
                                        )
                                        .build()

                                    analysis.setAnalyzer(analyzerExecutor) { imageProxy ->
                                        val mediaImage = imageProxy.image
                                        if (mediaImage == null || consumed.get()) {
                                            imageProxy.close()
                                            return@setAnalyzer
                                        }

                                        val image = InputImage.fromMediaImage(
                                            mediaImage,
                                            imageProxy.imageInfo.rotationDegrees,
                                        )

                                        scanner.process(image)
                                            .addOnSuccessListener { barcodes ->
                                                val raw = barcodes
                                                    .firstOrNull()
                                                    ?.rawValue
                                                    ?.trim()
                                                    .orEmpty()

                                                if (
                                                    raw.isNotEmpty() &&
                                                    consumed.compareAndSet(false, true)
                                                ) {
                                                    onResult(raw)
                                                }
                                            }
                                            .addOnCompleteListener {
                                                imageProxy.close()
                                            }
                                    }

                                    runCatching {
                                        provider.unbindAll()
                                        provider.bindToLifecycle(
                                            lifecycleOwner,
                                            CameraSelector.DEFAULT_BACK_CAMERA,
                                            preview,
                                            analysis,
                                        )
                                    }
                                }, ContextCompat.getMainExecutor(ctx))
                            }
                        },
                    )

                    Box(
                        modifier = Modifier
                            .fillMaxSize()
                            .background(Color.Black.copy(alpha = 0.20f)),
                    )

                    ScannerFrame(
                        modifier = Modifier
                            .align(Alignment.Center)
                            .size(270.dp),
                    )

                    Column(
                        modifier = Modifier
                            .align(Alignment.TopCenter)
                            .padding(top = 58.dp, start = 24.dp, end = 24.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        Text(
                            "اسکن QR سرویس",
                            color = Color.White,
                            fontSize = 24.sp,
                        )
                        Spacer(Modifier.height(8.dp))
                        Text(
                            "همان QR لینک اشتراک یا V2Ray را داخل کادر قرار دهید.",
                            color = Color.White.copy(alpha = 0.72f),
                            fontSize = 12.sp,
                        )
                    }

                    Column(
                        modifier = Modifier
                            .align(Alignment.BottomCenter)
                            .fillMaxWidth()
                            .padding(start = 28.dp, end = 28.dp, bottom = 34.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        if (!scanError.isNullOrBlank()) {
                            Text(
                                scanError.orEmpty(),
                                color = Color(0xFFFF9B92),
                                fontSize = 12.sp,
                            )
                            Spacer(Modifier.height(10.dp))
                        }
                        Button(
                            onClick = { galleryLauncher.launch("image/*") },
                            enabled = !galleryBusy,
                            modifier = Modifier.fillMaxWidth(),
                            colors = ButtonDefaults.buttonColors(
                                containerColor = ScannerAccent,
                                contentColor = Color(0xFF031719),
                            ),
                            shape = RoundedCornerShape(18.dp),
                        ) {
                            Text(
                                if (galleryBusy) "در حال بررسی تصویر…" else "انتخاب QR از گالری",
                            )
                        }
                        Spacer(Modifier.height(10.dp))
                        Button(
                            onClick = onClose,
                            modifier = Modifier.fillMaxWidth(),
                            colors = ButtonDefaults.buttonColors(
                                containerColor = Color(0xCC151A21),
                            ),
                            shape = RoundedCornerShape(18.dp),
                        ) {
                            Text("بستن اسکنر")
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun ScannerFrame(
    modifier: Modifier = Modifier,
) {
    Canvas(modifier = modifier) {
        val corner = size.minDimension * 0.20f
        val stroke = size.minDimension * 0.018f
        val inset = stroke / 2f

        fun line(start: Offset, end: Offset) {
            drawLine(
                color = ScannerAccent,
                start = start,
                end = end,
                strokeWidth = stroke,
                cap = StrokeCap.Round,
            )
        }

        line(Offset(inset, corner), Offset(inset, inset))
        line(Offset(inset, inset), Offset(corner, inset))

        line(Offset(size.width - corner, inset), Offset(size.width - inset, inset))
        line(Offset(size.width - inset, inset), Offset(size.width - inset, corner))

        line(
            Offset(inset, size.height - corner),
            Offset(inset, size.height - inset),
        )
        line(
            Offset(inset, size.height - inset),
            Offset(corner, size.height - inset),
        )

        line(
            Offset(size.width - corner, size.height - inset),
            Offset(size.width - inset, size.height - inset),
        )
        line(
            Offset(size.width - inset, size.height - inset),
            Offset(size.width - inset, size.height - corner),
        )

        drawRoundRect(
            color = Color.White.copy(alpha = 0.13f),
            style = Stroke(width = 1.5f),
            cornerRadius = androidx.compose.ui.geometry.CornerRadius(26f, 26f),
        )
    }
}
