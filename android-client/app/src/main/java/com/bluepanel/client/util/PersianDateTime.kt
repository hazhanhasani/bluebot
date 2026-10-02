package com.bluepanel.client.util

import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

object PersianDateTime {
    fun formatEpochSeconds(epochSeconds: Long?, includeTime: Boolean = true): String {
        if (epochSeconds == null || epochSeconds <= 0L) return "نامحدود"

        return runCatching {
            val dateTime = Instant.ofEpochSecond(epochSeconds)
                .atZone(ZoneId.systemDefault())
            val (jy, jm, jd) = gregorianToJalali(
                dateTime.year,
                dateTime.monthValue,
                dateTime.dayOfMonth,
            )
            val date = "%04d/%02d/%02d".format(Locale.US, jy, jm, jd)
            if (!includeTime) {
                toPersianDigits(date)
            } else {
                val time = DateTimeFormatter.ofPattern("HH:mm", Locale.US).format(dateTime)
                toPersianDigits("$date • $time")
            }
        }.getOrDefault("—")
    }

    fun formatDuration(seconds: Long): String {
        val safe = seconds.coerceAtLeast(0L)
        val hours = safe / 3_600L
        val minutes = (safe % 3_600L) / 60L
        val secs = safe % 60L
        return toPersianDigits(
            "%02d:%02d:%02d".format(Locale.US, hours, minutes, secs),
        )
    }

    fun toPersianDigits(value: String): String {
        val latin = "0123456789"
        val persian = "۰۱۲۳۴۵۶۷۸۹"
        return buildString(value.length) {
            value.forEach { char ->
                val index = latin.indexOf(char)
                append(if (index >= 0) persian[index] else char)
            }
        }
    }

    private fun gregorianToJalali(
        year: Int,
        month: Int,
        day: Int,
    ): Triple<Int, Int, Int> {
        val cumulativeGregorianDays = intArrayOf(
            0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334,
        )

        var gy = year
        var jy: Int
        if (gy > 1600) {
            jy = 979
            gy -= 1600
        } else {
            jy = 0
            gy -= 621
        }

        val gy2 = if (month > 2) gy + 1 else gy
        var days = 365 * gy +
            (gy2 + 3) / 4 -
            (gy2 + 99) / 100 +
            (gy2 + 399) / 400 -
            80 +
            day +
            cumulativeGregorianDays[month - 1]

        jy += 33 * (days / 12_053)
        days %= 12_053
        jy += 4 * (days / 1_461)
        days %= 1_461

        if (days > 365) {
            jy += (days - 1) / 365
            days = (days - 1) % 365
        }

        val jm: Int
        val jd: Int
        if (days < 186) {
            jm = 1 + days / 31
            jd = 1 + days % 31
        } else {
            jm = 7 + (days - 186) / 30
            jd = 1 + (days - 186) % 30
        }

        return Triple(jy, jm, jd)
    }
}
