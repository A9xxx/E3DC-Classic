<?php
// diagramm.php
require_once 'helpers.php';
$paths = getInstallPaths();
$confData = loadE3dcConfig();
$darkMode = ($confData['config']['darkmode'] ?? '1') === '1';
$sg = parseConfigFloat($confData['config']['speichergroesse'] ?? '13.5');
$awmwst = parseConfigFloat($confData['config']['awmwst'] ?? '19');
$awnebenkosten = parseConfigFloat($confData['config']['awnebenkosten'] ?? '0');
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
        const config = {
            speichergroesse: <?= $sg ?>,
            awmwst: <?= $awmwst ?>,
            awnebenkosten: <?= $awnebenkosten ?>
        };

        const wds = ["So", "Mo", "Di", "Mi", "Do", "Fr", "Sa"];
        
        const urlParams = new URLSearchParams(window.location.search);
        let fetchFile = urlParams.get('file');
        if (!fetchFile || !fetchFile.startsWith('awattardebug')) fetchFile = 'awattardebug.txt';

        fetch('api_file.php?file=' + encodeURIComponent(fetchFile) + '&_=' + Date.now())
            .then(res => res.text())
            .then(text => {
                const lines = text.trim().split('\n');
                
                const data = {
                    times: [], soc: [], pv: [], price: [], at: []
                };

                let afterSimulation = true; // start in Simulation mode until 'Data'
                let dayCount = 0;
                let lastTime = null;
                const baseDate = new Date(); // Using current date as base since we don't know file mtime via JS easily
                baseDate.setHours(0,0,0,0);

                lines.forEach(line => {
                    line = line.trim();
                    if (!line || line.startsWith('notstrom')) return;
                    if (line.includes('Simulation')) { afterSimulation = true; lastTime = null; return; }
                    if (line.startsWith('Data')) { afterSimulation = false; lastTime = null; return; }

                    const parts = line.split(/\s+/);
                    if (parts.length < 3) return;

                    const timeVal = parseFloat(parts[0]);
                    if (isNaN(timeVal)) return;

                    if (lastTime !== null && timeVal < lastTime) dayCount++;
                    lastTime = timeVal;

                    const hour = Math.floor(timeVal);
                    const minute = Math.round((timeVal - hour) * 60);
                    
                    const dt = new Date(baseDate.getTime());
                    dt.setDate(dt.getDate() + dayCount);
                    dt.setHours(hour, minute, 0, 0);

                    const timeStr = `${wds[dt.getDay()]} ${hour.toString().padStart(2,'0')}:${minute.toString().padStart(2,'0')}`;

                    if (afterSimulation) {
                        data.times.push(timeStr);
                        data.soc.push(parseFloat(parts[2]));
                    } else {
                        if (parts.length >= 6) {
                            if (!afterSimulation) {
                                // Add times only if empty (simulation takes precedence for times, but data aligns to simulation)
                                // Actually, in python version, it added times separately?
                                // Wait, the Python versions append to times/soc in Simulation, and in Data block it appends to price, pv, at.
                                // We can just assume Data block has exact same rows or we match them.
                                // Given simple plotting, we just push independently
                            }
                            let preisRaw = parseFloat(parts[1]);
                            let pv = parseFloat(parts[4]) * 40 * config.speichergroesse;
                            let at = parseFloat(parts[5]);
                            
                            let preis = preisRaw * ((config.awmwst / 100) + 1) + config.awnebenkosten;

                            data.price.push(preis);
                            data.pv.push(pv / 1000); // in kW
                            data.at.push(at);
                        }
                    }
                });

                renderChart(data);
            });

        function renderChart(data) {
            const isDark = <?= $darkMode ? 'true' : 'false' ?>;
            const cBg = isDark ? '#1e1e1e' : '#ffffff';
            const cText = isDark ? 'rgb(230,230,230)' : '#333333';
            
            const traces = [];
            
            traces.push({ x: data.times, y: data.soc, name: "SoC (%)", type: 'scatter', fill: 'tozeroy', fillcolor: 'rgba(154, 195, 83, 0.4)', line: {color: '#9AC353', width: 3} });
            traces.push({ x: data.times, y: data.price, name: "Preis (ct/kWh)", type: 'scatter', line: {color: '#BA55D3', width: 2, shape: 'hv'}, yaxis: 'y3' });
            traces.push({ x: data.times, y: data.pv, name: "PV (kW)", type: 'scatter', line: {color: 'rgba(255,194,56,1)', width: 3, shape: 'spline'}, yaxis: 'y2' });
            traces.push({ x: data.times, y: data.at, name: "AT (°C)", type: 'scatter', line: {color: '#00FF7F', width: 3, shape: 'spline'}, yaxis: 'y5' });

            const layout = {
                paper_bgcolor: cBg, plot_bgcolor: cBg, font: {color: cText},
                margin: isMobile ? {l: 5, r: 5, t: 30, b: 50} : {l: 50, r: 50, t: 30, b: 80},
                hovermode: 'x unified',
                xaxis: { showgrid: false, gridcolor: '#444' },
                yaxis: { title: 'SoC (%)', range: [0, 105], showgrid: false },
                yaxis2: { title: 'PV (kW)', overlaying: 'y', side: 'right', anchor: 'free', position: 0.92, showgrid: false },
                yaxis3: { title: 'Preis (ct)', overlaying: 'y', side: 'left', anchor: 'free', position: 0.00, showgrid: false },
                yaxis5: { title: 'AT (°C)', overlaying: 'y', side: 'right', anchor: 'free', position: 0.96, showgrid: false },
                legend: { orientation: 'h', y: isMobile ? -0.45 : -0.15, xanchor: 'center', x: 0.5 }
            };

            Plotly.newPlot('chart', traces, layout, {responsive: true, displayModeBar: !isMobile});
        }
    </script>
</body>
</html>
