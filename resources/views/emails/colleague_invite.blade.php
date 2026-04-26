<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Invitation Lisa</title>
</head>
<body style="margin:0;padding:0;background:#F4F4F7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1C1C1E;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F4F4F7;padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="background:#FFFFFF;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(15,15,30,0.06);">
        <tr><td style="padding:28px 28px 12px 28px;">
          <div style="font-size:13px;color:#6E6E73;letter-spacing:0.4px;font-weight:600;text-transform:uppercase;">Lisa · transmissions</div>
          <h1 style="margin:8px 0 0 0;font-size:22px;line-height:1.3;color:#1C1C1E;">{{ $senderName }} vous invite sur Lisa</h1>
        </td></tr>

        <tr><td style="padding:0 28px 16px 28px;color:#1C1C1E;font-size:15px;line-height:1.6;">
          <p style="margin:12px 0;">
            Bonjour,
          </p>
          <p style="margin:12px 0;">
            <strong>{{ $senderName }}</strong> ({{ $senderEmail }}) souhaite vous ajouter comme collègue dans Lisa
            pour vous transmettre la journée — chambres, patients, constantes et checklist —
            directement dans votre application.
          </p>
          <p style="margin:12px 0;color:#6E6E73;">
            Aucun compte Lisa n'existe pour <strong>{{ $recipient }}</strong>. Téléchargez Lisa et créez votre
            compte avec cette adresse pour recevoir automatiquement la transmission.
          </p>
        </td></tr>

        <tr><td align="center" style="padding:8px 28px 28px 28px;">
          <a href="https://lisa.osmoseconsulting.fr"
             style="display:inline-block;background:#7B61FF;color:#FFFFFF;text-decoration:none;font-weight:600;font-size:15px;padding:14px 22px;border-radius:12px;">
            Télécharger Lisa
          </a>
        </td></tr>

        <tr><td style="padding:0 28px 28px 28px;color:#9F9FA6;font-size:12px;line-height:1.5;border-top:1px solid #ECECEF;padding-top:18px;">
          Lisa est une aide à l'organisation pour soignants. Les décisions cliniques restent sous responsabilité humaine.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
