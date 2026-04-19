<?php
// live_diagramm.php
require_once 'helpers.php';
$paths = getInstallPaths();
$confData = loadE3dcConfig();
$darkMode = ($confData['config']['darkmode'] ?? '1') === '1';
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="<?= $darkMode ? 'dark' : 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
    <style>
        body { margin: 0; padding: 0; background: <?= $darkMode ? '#1e1e1e' : '#ffffff' ?>; color: <?= $darkMode ? '#e0e0e0' : '#333333' ?>; }
        #chart { width: 100vw; height: 100vh; }
    </style>
</head>
<body>
    <div id="chart"></div>
    <script>
        const isMobile = window.innerWidth <= 768;
        const urlParams = new URLSearchParams(window.location.search);
        const hours = parseInt(urlParams.get('hours')) || 6;
        const view = urlParams.get('view') || 'normal';
        const fileParam = urlParams.get('file') || '';
        
        const dataUrl = fileParam ? `tmp/history_backups/${fileParam}` : `ramdisk/live_history.txt`;

        fetch(dataUrl + '?_=' + Date.now())
            .then(res => res.text())
            .then(text => {
                const lines = text.trim().split('\n');
                const data = {
                    times: [], pv: [], bat: [], home: [], grid: [], soc: [], wb: [], price: [],
                    dc0_w: [], dc1_w: [], wb_p1: [], wb_p2: [], wb_p3: [],
                    ac0_w: [], ac1_w: [], ac2_w: [], bat_v: [], bat_a: []
                };

                const cutoff = Date.now() - (hours * 3600 * 1000);
                let currentMinute = null;
                let minuteEntries = [];

                function processMinute() {
                    if (minuteEntries.length === 0) return;
                    const e = minuteEntries[0]; // just take first entry for simplicity or average
                    const t = new Date(e.ts);
                    
                    data.times.push(t);
                    data.pv.push(e.pv || 0);
                    data.bat.push(e.bat || 0);
                    data.home.push(e.home || e.home_raw || 0);
                    data.grid.push(e.grid || 0);
                    data.soc.push(e.soc || 0);
                    data.wb.push(e.wb || 0);
                    data.price.push(e.price_ct || 0);
                    data.dc0_w.push(e.dc0_w || 0);
                    data.dc1_w.push(e.dc1_w || 0);
                    data.wb_p1.push(e.wb_p1 || 0);
                    data.wb_p2.push(e.wb_p2 || 0);
                    data.wb_p3.push(e.wb_p3 || 0);
                    data.ac0_w.push(e.ac0_w || 0);
                    data.ac1_w.push(e.ac1_w || 0);
                    data.ac2_w.push(e.ac2_w || 0);
                    data.bat_v.push(e.bat_v || 0);
                    data.bat_a.push(e.bat_a || 0);
                    
                    minuteEntries = [];
                }

                lines.forEach(line => {
                    if (!line.trim()) return;
                    try {
                        const j = JSON.parse(line);
                        if (!j.ts) return;
                        const t = new Date(j.ts);
                        if (isNaN(t) || t.getTime() < cutoff) return;
                        
                        const mStr = t.toISOString().substring(0,16);
                        if (currentMinute !== mStr) {
                            if (currentMinute !== null) processMinute();
                            currentMinute = mStr;
                        }
                        minuteEntries.push(j);
                    } catch (e) {}
                });
                processMinute();

                renderChart(data);
            });

        function renderChart(data) {
            const isDark = <?= $darkMode ? 'true' : 'false' ?>;
            const cBg = isDark ? '#1e1e1e' : '#ffffff';
            const cText = isDark ? 'rgb(230,230,230)' : '#333333';
            const cPrice = isDark ? '#BA55D3' : '#8E24AA';
            
            const traces = [];
            let y3Title = "Preis (ct)";

            if (view === 'pv') {
                traces.push({ x: data.times, y: data.dc0_w, name: "String 1 (W)", line: {color: '#FFD700', width: 2} });
                traces.push({ x: data.times, y: data.dc1_w, name: "String 2 (W)", line: {color: '#FF8C00', width: 2} });
                traces.push({ x: data.times, y: data.pv, name: "PV Gesamt (W)", line: {color: 'rgba(255,215,0,0.5)', width: 1, dash: 'dot'} });
            } else if (view === 'grid') {
                traces.push({ x: data.times, y: data.ac0_w, name: "Phase 1 (W)", line: {color: '#DC143C', width: 1.5} });
                traces.push({ x: data.times, y: data.ac1_w, name: "Phase 2 (W)", line: {color: '#1E90FF', width: 1.5} });
                traces.push({ x: data.times, y: data.ac2_w, name: "Phase 3 (W)", line: {color: '#32CD32', width: 1.5} });
                traces.push({ x: data.times, y: data.grid, name: "Netz Gesamt", line: {color: '#A9A9A9', width: 2, dash: 'dot'} });
            } else if (view === 'bat') {
                traces.push({ x: data.times, y: data.bat, name: "Leistung (W)", line: {color: '#32CD32', width: 2} });
            } else if (view === 'wb') {
                traces.push({ x: data.times, y: data.wb, name: "Wallbox (W)", line: {color: '#00CED1', width: 2} });
                traces.push({ x: data.times, y: data.wb_p1, name: "Phase 1", line: {color: '#DC143C', width: 1.5} });
                traces.push({ x: data.times, y: data.wb_p2, name: "Phase 2", line: {color: '#1E90FF', width: 1.5} });
                traces.push({ x: data.times, y: data.wb_p3, name: "Phase 3", line: {color: '#4169E1', width: 1.5} });
            } else {
                traces.push({ x: data.times, y: data.pv, name: "PV", line: {color: '#FFD700', width: 2.5} });
                traces.push({ x: data.times, y: data.bat.map(v => v > 1 ? v : 0), name: "Bat Entladung", fill: 'tozeroy', fillcolor: 'rgba(50,205,50,0.3)', line: {color: '#32CD32', width: 1.5} });
                traces.push({ x: data.times, y: data.bat.map(v => v < -1 ? Math.abs(v) : 0), name: "Bat Ladung", fill: 'tozeroy', fillcolor: 'rgba(34,139,34,0.3)', line: {color: '#228B22', width: 1.5, dash: 'dot'} });
                traces.push({ x: data.times, y: data.home, name: "Hausverbrauch", line: {color: '#4169E1', width: 2.5} });
                traces.push({ x: data.times, y: data.soc, name: "SoC", yaxis: 'y2', line: {color: '#cddc39', width: 1.5, dash:'dot'} });
                traces.push({ x: data.times, y: data.price, name: "Strompreis", yaxis: 'y3', line: {color: cPrice, width: 2, shape: 'hv'} });
                traces.push({ x: data.times, y: data.grid.map(v => v > 1 ? v : 0), name: "Netzbezug", line: {color: '#A9A9A9', width: 2, dash: 'dot'} });
                traces.push({ x: data.times, y: data.grid.map(v => v < -1 ? Math.abs(v) : 0), name: "Einspeisung", line: {color: '#FF8C00', width: 2, dash: 'dot'} });
                traces.push({ x: data.times, y: data.wb, name: "Wallbox", line: {color: '#00CED1', width: 2} });
            }

            const layout = {
                paper_bgcolor: cBg, plot_bgcolor: cBg, font: {color: cText},
                margin: isMobile ? {l: 5, r: 5, t: 30, b: 50} : {l: 50, r: 50, t: 30, b: 50},
                hovermode: 'x unified',
                xaxis: { showgrid: false, gridcolor: '#444' },
                yaxis: { title: 'Leistung (W)', showgrid: true, gridcolor: isDark ? '#333' : '#eee' },
                yaxis2: { title: 'SoC / %', overlaying: 'y', side: 'right', range: [0, 105], showgrid: false, position: 1.0 },
                yaxis3: { title: y3Title, overlaying: 'y', side: 'right', anchor: 'free', position: 0.95, showgrid: false },
                legend: { orientation: 'h', y: isMobile ? -0.45 : -0.15, xanchor: 'center', x: 0.5 }
            };

            Plotly.newPlot('chart', traces, layout, {responsive: true, displayModeBar: !isMobile});
        }
    </script>
</body>
</html>
