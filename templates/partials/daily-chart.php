<?php
/**
 * Daily inquiries bar chart (inline SVG, CSP-safe: no inline styles or scripts).
 * $daily is either Y-m-d => int (single series) or Y-m-d => [type => int] (stacked by type,
 * bottom-up in the fixed order contact, quote, sample, other, with a legend).
 * Each day column links to the inbox filtered to that local day.
 *
 * @var array<string,int|array<string,int>> $daily
 * @var array $baseQuery extra inbox filters (e.g. website)
 */
use App\Domain\Submissions\Presenter;

$baseQuery ??= [];
$stacked = $daily !== [] && is_array(reset($daily));
$types = array_keys(Presenter::TYPE_LABELS);
$totals = array_map(static fn ($v) => is_array($v) ? array_sum($v) : (int) $v, $daily);
$n = count($daily);
$max = max(1, ...array_values($totals ?: [0]));
// Round the axis maximum up to a friendly even number so the midpoint tick is an integer.
if ($max <= 10) {
    $axisMax = max(2, (int) (ceil($max / 2) * 2));
} else {
    $mag = (int) (10 ** floor(log10($max)));
    $axisMax = $mag * 10;
    foreach ([1, 2, 4, 5, 10] as $m) {
        if ($m * $mag >= $max) {
            $axisMax = $m * $mag;
            break;
        }
    }
}
$W = 720; $H = 210; $padL = 34; $padB = 24; $padT = 8;
$plotW = $W - $padL - 4; $plotH = $H - $padB - $padT;
$slot = $n > 0 ? $plotW / $n : $plotW;
$barW = max(1.0, min(28.0, $slot - 2));
$r = min(4.0, $barW / 2);
$scale = static fn (float $v): float => ($v / $axisMax) * $plotH;
$base = $padT + $plotH;
$ticks = array_unique([0, (int) round($axisMax / 2), $axisMax]);
$dates = array_keys($daily);
$labelIdx = $n <= 1 ? [0] : array_unique([0, intdiv($n - 1, 2), $n - 1]);
$total = array_sum($totals);
$f = static fn (float $v): string => (string) round($v, 1);
/** Rect with only the top corners rounded. */
$roundedTop = static function (float $x, float $y, float $w, float $h, float $r) use ($f): string {
    $r = min($r, $h, $w / 2);
    return 'M' . $f($x) . ',' . $f($y + $h) . ' V' . $f($y + $r) . ' Q' . $f($x) . ',' . $f($y) . ' ' . $f($x + $r) . ',' . $f($y)
        . ' H' . $f($x + $w - $r) . ' Q' . $f($x + $w) . ',' . $f($y) . ' ' . $f($x + $w) . ',' . $f($y + $r) . ' V' . $f($y + $h) . ' Z';
};
?>
<figure class="chart" aria-labelledby="trend-title">
    <figcaption class="chart-head">
        <h2 id="trend-title" class="h3 with-icon"><?= \App\Support\Icons::svg('chart', 18) ?> Inquiries per day</h2>
        <span class="muted small"><?= e(number_format($total)) ?> total · <?= e($ctx->timezone()) ?></span>
    </figcaption>
    <?php if ($stacked): ?>
        <ul class="legend" aria-label="Legend">
            <?php foreach (Presenter::TYPE_LABELS as $type => $label): ?>
                <li><span class="swatch swatch-<?= e($type) ?>" aria-hidden="true"></span><?= e($label) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <svg class="chart-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Bar chart of inquiries per day<?= $stacked ? ' by type' : '' ?>, <?= e(number_format($total)) ?> in total. A data table follows.">
        <?php foreach ($ticks as $t): $ty = $base - $scale($t); ?>
            <line class="chart-grid" x1="<?= $padL ?>" x2="<?= $W - 4 ?>" y1="<?= $f($ty) ?>" y2="<?= $f($ty) ?>"></line>
            <text class="chart-axis" x="<?= $padL - 6 ?>" y="<?= $f($ty + 4) ?>" text-anchor="end"><?= e($t) ?></text>
        <?php endforeach; ?>
        <?php foreach ($dates as $i => $date):
            $x = $padL + $i * $slot + ($slot - $barW) / 2;
            $v = $totals[$date];
            $label = date_create($date)->format('D d M Y') . ': ' . $v . ' inquir' . ($v === 1 ? 'y' : 'ies');
            if ($stacked && $v > 0) {
                $parts = [];
                foreach ($types as $type) {
                    if ($daily[$date][$type] > 0) {
                        $parts[] = Presenter::TYPE_LABELS[$type] . ' ' . $daily[$date][$type];
                    }
                }
                $label .= ' — ' . implode(', ', $parts);
            }
            ?>
            <a href="<?= e($ctx->url('/inbox', $baseQuery + ['from' => $date, 'to' => $date])) ?>" class="chart-hit" aria-label="<?= e($label) ?>" data-tip="<?= e($label) ?>">
                <rect class="chart-hit-area" x="<?= $f($padL + $i * $slot) ?>" y="<?= $padT ?>" width="<?= $f($slot) ?>" height="<?= $plotH ?>"></rect>
                <?php if ($v > 0):
                    $segments = $stacked ? array_filter(array_map(static fn ($t) => [$t, $daily[$date][$t]], $types), static fn ($s) => $s[1] > 0) : [['total', $v]];
                    $segments = array_values($segments);
                    $y = $base;
                    foreach ($segments as $k => [$type, $count]):
                        $h = $scale($count);
                        $isTop = $k === count($segments) - 1;
                        // 2px surface gap between stacked segments.
                        $gap = $k > 0 && $h > 3 ? 2 : 0;
                        $top = $y - $h;
                        $cls = $stacked ? 'chart-bar bar-' . $type : 'chart-bar';
                        if ($isTop): ?>
                            <path class="<?= e($cls) ?>" d="<?= e($roundedTop($x, $top, $barW, $h - $gap, $r)) ?>"></path>
                        <?php else: ?>
                            <rect class="<?= e($cls) ?>" x="<?= $f($x) ?>" y="<?= $f($top) ?>" width="<?= $f($barW) ?>" height="<?= $f(max(0.5, $h - $gap)) ?>"></rect>
                        <?php endif;
                        $y = $top;
                    endforeach;
                endif; ?>
            </a>
        <?php endforeach; ?>
        <line class="chart-baseline" x1="<?= $padL ?>" x2="<?= $W - 4 ?>" y1="<?= $base ?>" y2="<?= $base ?>"></line>
        <?php foreach ($labelIdx as $i): ?>
            <text class="chart-axis" x="<?= $f($padL + $i * $slot + $slot / 2) ?>" y="<?= $H - 6 ?>" text-anchor="<?= $i === 0 && $n > 1 ? 'start' : ($i === $n - 1 && $n > 1 ? 'end' : 'middle') ?>"><?= e(date_create($dates[$i])->format('d M')) ?></text>
        <?php endforeach; ?>
    </svg>
    <details class="chart-table">
        <summary>Show as table</summary>
        <div class="table-wrap">
            <table class="table table-compact">
                <thead><tr><th scope="col">Date</th>
                    <?php if ($stacked): foreach (Presenter::TYPE_LABELS as $label): ?><th scope="col" class="num"><?= e($label) ?></th><?php endforeach; endif; ?>
                    <th scope="col" class="num">Total</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($daily, true) as $date => $v): ?>
                    <tr><td><?= e(date_create($date)->format('D d M Y')) ?></td>
                        <?php if ($stacked): foreach ($types as $type): ?><td class="num"><?= e($v[$type]) ?></td><?php endforeach; endif; ?>
                        <td class="num"><a href="<?= e($ctx->url('/inbox', $baseQuery + ['from' => $date, 'to' => $date])) ?>"><?= e($totals[$date]) ?></a></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
</figure>
