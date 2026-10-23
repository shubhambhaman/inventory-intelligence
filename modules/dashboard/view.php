<div class="module-view module-dashboard" data-module-view="dashboard">
        <div class="page__grid">

            <!-- ===== MAIN COLUMN ===== -->
            <div class="page__main">

                <div class="page-head">
                    <h1 class="page-title">Inventory Intelligence Dashboard</h1>
                    <p class="page-sub">Welcome back, <?= $e($firstName) ?>! Here's what's happening with your inventory.</p>
                </div>

                <div class="tabs" role="tablist" aria-label="Dashboard views">
                    <?php foreach ($tabs as $i => $tab): ?>
                        <button type="button" role="tab" class="tabs__tab<?= $i === 0 ? ' is-active' : '' ?>"
                                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                                data-tab="<?= $e(strtolower(str_replace(' ', '-', $tab))) ?>"><?= $e($tab) ?></button>
                    <?php endforeach; ?>
                </div>

                <!-- KPI cards -->
                <section class="kpis" aria-label="Key figures">
                    <?php foreach ($kpis as $k): ?>
                        <article class="ds-card kpi">
                            <div class="kpi__top">
                                <span class="tile tile--<?= $e($k['tone']) ?>"><i class="bi <?= $e($k['icon']) ?>"></i></span>
                                <div class="kpi__text">
                                    <span class="kpi__label"><?= $e($k['label']) ?></span>
                                    <span class="kpi__value"><?= $e($k['value']) ?></span>
                                </div>
                            </div>
                            <div class="kpi__foot">
                                <?php if ($k['delta'] !== null): ?>
                                    <span class="delta delta--up"><i class="bi bi-arrow-up"></i><?= $e($k['delta']) ?></span>
                                <?php endif; ?>
                                <span class="kpi__note"><?= $e($k['note']) ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <!-- Health / Trend / Stock status -->
                <section class="insights">
                    <article class="ds-card insights__health">
                        <header class="ds-card__head"><h2 class="ds-card__title">Inventory Health Overview</h2></header>
                        <div class="health">
                            <div class="donut">
                                <canvas id="health-chart" role="img" aria-label="Inventory health breakdown donut chart"></canvas>
                                <div class="donut__center">
                                    <strong><?= $e($health['total']) ?></strong>
                                    <span>Total Value</span>
                                </div>
                            </div>
                            <ul class="legend">
                                <?php foreach ($health['items'] as $h): ?>
                                    <li class="legend__row">
                                        <span class="legend__dot" style="background:<?= $e($h['color']) ?>"></span>
                                        <span class="legend__name"><?= $e($h['label']) ?></span>
                                        <span class="legend__value"><?= $e($h['value']) ?></span>
                                        <span class="legend__pct"><?= $e($h['pct']) ?>%</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </article>

                    <article class="ds-card insights__trend">
                        <header class="ds-card__head">
                            <h2 class="ds-card__title">Inventory Value Trend</h2>
                            <label class="ds-select">
                                <select id="trend-range" aria-label="Trend range">
                                    <option value="3">Last 3 Months</option>
                                    <option value="6" selected>Last 6 Months</option>
                                    <option value="12">Last 12 Months</option>
                                </select>
                                <i class="bi bi-chevron-down"></i>
                            </label>
                        </header>
                        <div class="chart-box"><canvas id="trend-chart" role="img" aria-label="Inventory value trend chart"></canvas></div>
                    </article>

                    <article class="ds-card insights__status">
                        <header class="ds-card__head">
                            <h2 class="ds-card__title">Stock Status</h2>
                            <a class="link" href="#">View All <i class="bi bi-arrow-right"></i></a>
                        </header>
                        <ul class="status-list">
                            <?php foreach ($stockStatus as $s): ?>
                                <li>
                                    <a class="status-row status-row--<?= $e($s['tone']) ?>" href="#">
                                        <i class="bi <?= $e($s['icon']) ?>"></i>
                                        <span class="status-row__label"><?= $e($s['label']) ?></span>
                                        <span class="status-row__count"><?= $e($s['count']) ?> SKUs</span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                </section>

                <!-- Decision inbox -->
                <section class="ds-card inbox" aria-labelledby="inbox-title">
                    <header class="ds-card__head ds-card__head--top">
                        <div>
                            <h2 class="ds-card__title" id="inbox-title">
                                <i class="bi bi-inbox inbox__icon"></i>Decision Inbox
                                <span class="count-badge"><?= (int) $pendingDecisions ?></span>
                            </h2>
                            <p class="ds-card__sub">Key actions that require your attention</p>
                        </div>
                        <a class="link" href="#">View All <i class="bi bi-arrow-right"></i></a>
                    </header>
                    <div class="table-wrap">
                        <table class="ds-table">
                            <thead>
                                <tr>
                                    <th>Priority</th><th>Item</th><th>Type</th><th>Recommendation</th>
                                    <th>Expected Impact</th><th>Confidence</th><th class="ta-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($decisions as $d): ?>
                                    <tr>
                                        <td><span class="prio prio--<?= $e(strtolower($d['priority'])) ?>"><?= $e($d['priority']) ?></span></td>
                                        <td class="cell-strong"><?= $e($d['item']) ?></td>
                                        <td><span class="type"><i class="bi <?= $e($d['typeIcon']) ?>"></i><?= $e($d['type']) ?></span></td>
                                        <td>
                                            <span class="cell-main"><?= $e($d['rec']) ?></span>
                                            <?php if ($d['recSub'] !== ''): ?><span class="cell-sub"><?= $e($d['recSub']) ?></span><?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="cell-main"><?= $e($d['impact']) ?></span>
                                            <?php if ($d['impactSub'] !== ''): ?><span class="cell-sub"><?= $e($d['impactSub']) ?></span><?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="conf">
                                                <span class="conf__bar"><span style="width:<?= (int) $d['confidence'] ?>%"></span></span>
                                                <?= (int) $d['confidence'] ?>%
                                            </span>
                                        </td>
                                        <td class="ta-right">
                                            <button type="button" class="ds-btn ds-btn--soft ds-btn--sm" data-review="<?= $e($d['item']) ?>">Review</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- ===== RIGHT RAIL ===== -->
            <aside class="page__rail" aria-label="Assistant and quick actions">

                <section class="ds-card assistant" id="assistant">
                    <header class="assistant__head">
                        <h2 class="ds-card__title"><i class="bi bi-stars assistant__spark"></i>AI Assistant</h2>
                        <span class="ds-badge ds-badge--violet">Beta</span>
                    </header>
                    <p class="ds-card__sub">Ask anything about your inventory...</p>

                    <div class="assistant__log" id="assistant-log" aria-live="polite"></div>

                    <ul class="suggest" id="assistant-suggest">
                        <?php foreach ($assistantSuggestions as $q): ?>
                            <li><button type="button" class="suggest__btn" data-question="<?= $e($q) ?>">
                                <i class="bi bi-stars"></i><span><?= $e($q) ?></span>
                            </button></li>
                        <?php endforeach; ?>
                    </ul>

                    <form class="ask" id="assistant-form" autocomplete="off">
                        <input type="text" class="ask__input" id="assistant-input" maxlength="500"
                               placeholder="Type your question..." aria-label="Ask the AI assistant">
                        <button type="submit" class="ask__send" aria-label="Send question"><i class="bi bi-send-fill"></i></button>
                    </form>
                </section>

                <section class="ds-card quick" aria-labelledby="quick-title">
                    <h2 class="ds-card__title" id="quick-title">Quick Actions</h2>
                    <div class="quick__list">
                        <?php foreach ($quickActions as $a): ?>
                            <button type="button" class="quick__item" data-action="<?= $e($a['key']) ?>">
                                <span class="tile tile--<?= $e($a['tone']) ?>"><i class="bi <?= $e($a['icon']) ?>"></i></span>
                                <span class="quick__text">
                                    <span class="quick__title"><?= $e($a['title']) ?></span>
                                    <span class="quick__sub"><?= $e($a['sub']) ?></span>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </div>

        <!-- Complete business platform -->
        <section class="platform" aria-labelledby="platform-title">
            <div class="platform__head">
                <h2 class="platform__title" id="platform-title">Complete Business Platform</h2>
                <p class="platform__sub">Inventory is just the beginning. Here's what's coming next.</p>
            </div>
            <div class="platform__grid">
                <?php foreach ($modules as $m): ?>
                    <article class="ds-card module module--<?= $e($m['status']) ?>">
                        <header class="module__head">
                            <span class="tile tile--<?= $e($m['tone']) ?>"><i class="bi <?= $e($m['icon']) ?>"></i></span>
                            <h3 class="module__title"><?= $e($m['title']) ?></h3>
                            <?php if ($m['status'] === 'active'): ?>
                                <span class="ds-badge ds-badge--green">Active</span>
                            <?php else: ?>
                                <span class="ds-badge ds-badge--muted">Soon</span>
                            <?php endif; ?>
                        </header>
                        <ul class="module__list">
                            <?php foreach ($m['items'] as $item): ?>
                                <li><i class="bi <?= $m['status'] === 'active' ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i><?= $e($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
</div>
