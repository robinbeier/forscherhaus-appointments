type AppointmentPdfData = {
    schoolName: string; // Forscherhaus
    title: string; // Klassenleitungssprechtage
    teacher: string; // Robin Beier
    room: string; // Physikraum
    startISO: string; // 2025-11-27T09:00:00+01:00
    endISO: string; // 2025-11-27T09:25:00+01:00
    durationMin: number; // 25
    manageUrl: string; // https://.../t/abc123
    qrPngDataUrl?: string; // data:image/png;base64,...
    locale?: string; // de-DE
    timezone?: string; // Europe/Berlin
    appointmentId?: string; // optional für Footer/Referenz
};
