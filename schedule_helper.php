<?php

function calculateEstimatedFinishDate(mysqli $conn, string $startDate, int $workingDays): string
{
    $date = new DateTime($startDate);
    $remaining = max(1, $workingDays);
    $holidays = [];

    $holidayResult = $conn->query("SELECT holiday_date FROM shop_holidays WHERE holiday_date >= '" . $conn->real_escape_string($startDate) . "'");
    if ($holidayResult) {
        while ($holiday = $holidayResult->fetch_assoc()) {
            $holidays[$holiday['holiday_date']] = true;
        }
    }

    while ($remaining > 0) {
        $date->modify('+1 day');
        $dateKey = $date->format('Y-m-d');
        if ((int)$date->format('N') < 6 && !isset($holidays[$dateKey])) {
            $remaining--;
        }
    }

    return $date->format('Y-m-d');
}
