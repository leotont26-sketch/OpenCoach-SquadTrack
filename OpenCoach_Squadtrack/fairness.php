<?php
declare(strict_types=1);

function squadtrack_fairness_settings(): array
{
    $settings = function_exists('app_settings') ? app_settings() : [];
    $balancedMax = (int)($settings['fairness_balanced_max'] ?? 15);
    $noticeMax = (int)($settings['fairness_notice_max'] ?? 30);

    if ($balancedMax < 0) {
        $balancedMax = 15;
    }
    if ($noticeMax < 0) {
        $noticeMax = 30;
    }
    if ($noticeMax <= $balancedMax) {
        $balancedMax = 15;
        $noticeMax = 30;
    }

    return [
        'balanced_max' => $balancedMax,
        'notice_max' => $noticeMax,
    ];
}

function squadtrack_calculate_fairness(int $trainingQuote, int $matchQuote, int $trainingTotal, int $matchTotal): array
{
    if ($trainingTotal <= 0 && $matchTotal <= 0) {
        return [
            'status' => 'muted',
            'class' => 'muted',
            'short' => 'Keine Daten',
            'text' => 'Noch keine Trainings- oder Spieltagsdaten vorhanden.',
            'diff' => 0,
        ];
    }

    if ($trainingTotal <= 0) {
        return [
            'status' => 'muted',
            'class' => 'muted',
            'short' => 'Zu wenig Daten',
            'text' => 'Noch keine regulären Trainingsdaten im gewählten Zeitraum.',
            'diff' => 0,
        ];
    }

    if ($matchTotal <= 0) {
        return [
            'status' => 'muted',
            'class' => 'muted',
            'short' => 'Zu wenig Daten',
            'text' => 'Noch keine Spieltagsdaten im gewählten Zeitraum.',
            'diff' => 0,
        ];
    }

    $limits = squadtrack_fairness_settings();
    $diff = $trainingQuote - $matchQuote;
    $absDiff = abs($diff);

    if ($absDiff < $limits['balanced_max']) {
        return [
            'status' => 'good',
            'class' => 'good',
            'short' => 'Ausgeglichen',
            'text' => 'Training und Spieltage sind im gewählten Zeitraum stimmig.',
            'diff' => $diff,
        ];
    }

    if ($absDiff < $limits['notice_max']) {
        return [
            'status' => 'mid',
            'class' => 'mid',
            'short' => 'Auffällig',
            'text' => $diff > 0
                ? 'Leicht wenig Einsatz trotz besserer Trainingsquote.'
                : 'Leicht viel Einsatz trotz geringerer Trainingsquote.',
            'diff' => $diff,
        ];
    }

    return [
        'status' => 'bad',
        'class' => 'bad',
        'short' => 'Fragwürdig',
        'text' => $diff > 0
            ? 'Wenig Einsatz trotz hoher Trainingsbeteiligung.'
            : 'Viel Einsatz trotz geringer Trainingsquote.',
        'diff' => $diff,
    ];
}
