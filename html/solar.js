/**
 * Berechnet Sonnenstand und theoretische PV-Leistung.
 * Benötigt die globalen Konstanten: PV_STRINGS, LAT, LON
 */

function getSunPosition() {
    if (typeof LAT === 'undefined' || typeof LON === 'undefined') return null;
    const now = new Date();
    const rad = Math.PI / 180;
    const start = new Date(now.getFullYear(), 0, 0);
    const diff = now - start;
    const dayOfYear = Math.floor(diff / (1000 * 60 * 60 * 24));
    
    const B = (360 / 365) * (dayOfYear - 81) * rad;
    const declination = 23.45 * Math.sin(B) * rad;
    const eot = 9.87 * Math.sin(2 * B) - 7.53 * Math.cos(B) - 1.5 * Math.sin(B);
    
    const lst = now.getUTCHours() + now.getUTCMinutes() / 60 + LON / 15 + eot / 60;
    const omega = (lst - 12) * 15 * rad;
    const latRad = LAT * rad;
    
    const sinEl = Math.sin(latRad) * Math.sin(declination) + Math.cos(latRad) * Math.cos(declination) * Math.cos(omega);
    const el = Math.asin(sinEl);
    
    return { el, sinEl, declination, omega, latRad };
}

function isDaytime() {
    const pos = getSunPosition();
    if (!pos) return true;
    // Tag = Elevation > -3 Grad (bürgerliche Dämmerung beginnt bei -6, aber für PV/Optik ist -3 gut)
    return (pos.el * 180 / Math.PI) > -3;
}

function getTheoreticalPower() {
    if (typeof PV_STRINGS === 'undefined' || !PV_STRINGS || PV_STRINGS.length === 0) return 0;
    
    const pos = getSunPosition();
    if (!pos || pos.el < 0) return 0; // Nacht (geometrisch)
    
    const cosAzS = (Math.sin(pos.latRad) * pos.sinEl - Math.sin(pos.declination)) / (Math.cos(pos.latRad) * Math.cos(pos.el));
    let sunAz = Math.acos(Math.min(1, Math.max(-1, cosAzS)));
    if (pos.omega < 0) sunAz = -sunAz;
    
    let totalW = 0;
    const airMass = 1 / Math.max(0.05, pos.sinEl);
    const transmission = (typeof PV_ATMOSPHERE !== 'undefined') ? PV_ATMOSPHERE : 0.7;
    const intensityFactor = 1.35 * Math.pow(transmission, Math.pow(airMass, 0.678));

    for (let s of PV_STRINGS) {
        const tiltRad = s.tilt * (Math.PI / 180);
        const panelAz = s.azimuth * (Math.PI / 180);
        const cosTheta = pos.sinEl * Math.cos(tiltRad) + Math.cos(pos.el) * Math.sin(tiltRad) * Math.cos(sunAz - panelAz);
        if (cosTheta > 0) totalW += s.power * cosTheta * intensityFactor;
    }
    return totalW;
}