<?php
/**
 * Weather panel. Expects $wx (from weather()) and $city['name_en'].
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }
?>
<section class="panel weather" aria-labelledby="weather-title">
    <h2 id="weather-title" class="panel__title">Weather in <?= e($city['name_en'] ?? $city['city_name'] ?? '') ?></h2>
    <?php if (!$wx): ?>
        <p class="muted">Live weather is unavailable right now.</p>
    <?php else:
        $now = $wx['current'];
        [$label, $icon] = weather_label((int) $now['weather_code']);
    ?>
        <p class="weather__now">
            <span class="weather__icon" aria-hidden="true"><?= $icon ?></span>
            <span class="weather__temp"><?= (int) round((float) $now['temperature_2m']) ?>°C</span>
            <span><?= e($label) ?><br>
                <span class="muted small">Feels like <?= (int) round((float) $now['apparent_temperature']) ?>°C,
                humidity <?= (int) $now['relative_humidity_2m'] ?>%</span></span>
        </p>
        <ol class="forecast">
            <?php foreach ($wx['daily']['time'] as $i => $day): ?>
                <?php [$dLabel, $dIcon] = weather_label((int) $wx['daily']['weather_code'][$i]); ?>
                <li>
                    <span class="forecast__day"><?= $i === 0 ? 'Today' : e(date('D', strtotime($day))) ?></span>
                    <span aria-hidden="true"><?= $dIcon ?></span>
                    <span class="visually-hidden"><?= e($dLabel) ?></span>
                    <span><?= (int) round((float) $wx['daily']['temperature_2m_max'][$i]) ?>° / <?= (int) round((float) $wx['daily']['temperature_2m_min'][$i]) ?>°</span>
                    <span class="muted small"><?= (int) ($wx['daily']['precipitation_probability_max'][$i] ?? 0) ?>% rain</span>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($tips = weather_advice($wx)): ?>
            <ul class="tips">
                <?php foreach ($tips as $tip): ?><li><?= e($tip) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="small muted">Forecast from <a href="https://open-meteo.com/" rel="noopener" target="_blank">Open-Meteo</a>, updated hourly.</p>
    <?php endif; ?>
</section>
