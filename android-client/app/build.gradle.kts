import java.net.URI
import java.security.MessageDigest
import java.util.zip.ZipFile

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

val blueBotApi = providers.gradleProperty("BLUEBOT_API_BASE")
    .orElse("https://bot.blluepanel.ir/api/client.php")
val libXrayVersion = "26.9.9"
val libXraySha256 = "4998a8b56e4a78a164b5359d5690036f83da3b575465cea57ddf29c0149c345f"
val libXrayArchive = layout.buildDirectory.file("downloads/libxray-android.zip")
val libXrayAar = layout.projectDirectory.file("libs/libXray.aar")

val prepareLibXray by tasks.registering {
    outputs.file(libXrayAar)
    doLast {
        val archive = libXrayArchive.get().asFile
        val aar = libXrayAar.asFile
        archive.parentFile.mkdirs()
        aar.parentFile.mkdirs()

        if (!archive.exists()) {
            val url = URI("https://github.com/XTLS/libXray/releases/download/v$libXrayVersion/libxray-android.zip").toURL()
            url.openStream().use { input -> archive.outputStream().use { input.copyTo(it) } }
        }

        val digest = MessageDigest.getInstance("SHA-256")
        archive.inputStream().use { input ->
            val buffer = ByteArray(1024 * 1024)
            while (true) {
                val read = input.read(buffer)
                if (read <= 0) break
                digest.update(buffer, 0, read)
            }
        }
        val actual = digest.digest().joinToString("") { "%02x".format(it) }
        check(actual == libXraySha256) {
            "libXray checksum mismatch: expected $libXraySha256, got $actual"
        }

        ZipFile(archive).use { zip ->
            val entry = zip.entries().asSequence().firstOrNull { !it.isDirectory && it.name.endsWith(".aar") }
                ?: error("No AAR found inside libxray-android.zip")
            zip.getInputStream(entry).use { input -> aar.outputStream().use { input.copyTo(it) } }
        }
    }
}

android {
    namespace = "com.bluepanel.client"
    compileSdk = 37

    defaultConfig {
        applicationId = "com.bluepanel.client"
        minSdk = 26
        targetSdk = 37
        versionCode = 1
        versionName = "0.1.0"

        buildConfigField("String", "BLUEBOT_API_BASE", "\"${blueBotApi.get()}\"")
        buildConfigField("String", "XRAY_CORE_VERSION", "\"$libXrayVersion\"")
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    packaging {
        resources.excludes += setOf(
            "META-INF/DEPENDENCIES",
            "META-INF/LICENSE*",
            "META-INF/NOTICE*"
        )
    }
}

tasks.named("preBuild").configure { dependsOn(prepareLibXray) }

dependencies {
    implementation(files("libs/libXray.aar"))

    val composeBom = platform("androidx.compose:compose-bom:2026.09.00")
    implementation(composeBom)
    androidTestImplementation(composeBom)

    implementation("androidx.core:core-ktx:1.19.1")
    implementation("androidx.activity:activity-compose:1.13.0")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")

    debugImplementation("androidx.compose.ui:ui-tooling")
}
