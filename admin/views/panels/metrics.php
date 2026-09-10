<div class="metrics-grid">
    <div class="card metrics-chart-card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Activity over time</h2>
                <p class="card-subtitle">Daily totals for the selected metric</p>
            </div>
            <div class="metrics-toolbar">
                <select class="form-input" id="metricsMetric">
                    <option value="signups">New signups</option>
                    <option value="comments">New comments</option>
                    <option value="views">Video views</option>
                    <option value="searches">Searches</option>
                </select>
                <select class="form-input" id="metricsRange">
                    <option value="7">Last 7 days</option>
                    <option value="30" selected>Last 30 days</option>
                    <option value="90">Last 90 days</option>
                </select>
            </div>
        </div>
        <div class="card-body">
            <div class="metrics-chart-wrap">
                <!-- admin-metrics.js sets the viewBox to the SVG's pixel size on
                     every draw, so 1 unit = 1px and axis text is never stretched. -->
                <svg id="metricsChart" class="metrics-chart" viewBox="0 0 600 220" aria-hidden="true"></svg>
                <div class="metrics-chart-empty" id="metricsChartEmpty" style="display:none;">
                    No data for this range yet.
                </div>
            </div>
            <div class="metrics-chart-summary" id="metricsChartSummary"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Top videos</h2>
                <p class="card-subtitle">Most-watched in the last 30 days</p>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <ol class="metrics-rank-list" id="metricsTopVideos">
                <li class="metrics-rank-empty">Loading…</li>
            </ol>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Top searches</h2>
                <p class="card-subtitle">What members are looking for</p>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <ol class="metrics-rank-list" id="metricsTopSearches">
                <li class="metrics-rank-empty">Loading…</li>
            </ol>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Top commenters</h2>
                <p class="card-subtitle">Most active members (last 30 days)</p>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <ol class="metrics-rank-list" id="metricsTopCommenters">
                <li class="metrics-rank-empty">Loading…</li>
            </ol>
        </div>
    </div>
</div>
