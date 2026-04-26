<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Transmission Lisa — {{ $humanDate }}</title>
</head>
<body style="margin:0;padding:0;background:#F4F4F7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1C1C1E;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F4F4F7;padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="background:#FFFFFF;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(15,15,30,0.06);">

        {{-- Header --}}
        <tr><td style="padding:28px 28px 8px 28px;">
          <div style="font-size:12px;color:#7B61FF;letter-spacing:0.5px;font-weight:700;text-transform:uppercase;">Lisa · transmission</div>
          <h1 style="margin:6px 0 0 0;font-size:24px;line-height:1.25;color:#1C1C1E;">{{ ucfirst($humanDate) }}</h1>
          <div style="margin-top:6px;font-size:14px;color:#6E6E73;">
            De <strong style="color:#1C1C1E;">{{ $senderName }}</strong> &middot; {{ $senderEmail }}
          </div>
        </td></tr>

        @if(!empty($message))
          <tr><td style="padding:18px 28px 0 28px;">
            <div style="background:#F5F2FF;border:1px solid #E2DAFF;border-radius:12px;padding:14px 16px;color:#1C1C1E;font-size:14px;line-height:1.55;">
              <div style="font-size:11px;font-weight:700;color:#7B61FF;letter-spacing:0.5px;text-transform:uppercase;margin-bottom:6px;">Mot de l'expéditeur</div>
              {!! nl2br(e($message)) !!}
            </div>
          </td></tr>
        @endif

        {{-- Stats --}}
        <tr><td style="padding:18px 28px 8px 28px;">
          @php
            $totalPatients = collect($rooms)->sum(fn($r) => count($r['patients'] ?? []));
            $totalVitals   = collect($rooms)->flatMap(fn($r) => $r['patients'] ?? [])->sum(fn($p) => count($p['vitals'] ?? []));
            $totalChecks   = collect($rooms)->flatMap(fn($r) => $r['patients'] ?? [])->sum(fn($p) => count($p['checklist'] ?? []));
          @endphp
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
            <tr>
              <td style="background:#F8F8FA;border-radius:10px;padding:10px 12px;text-align:center;">
                <div style="font-size:20px;font-weight:700;color:#1C1C1E;">{{ count($rooms) }}</div>
                <div style="font-size:11px;color:#6E6E73;text-transform:uppercase;letter-spacing:0.4px;font-weight:600;">Chambres</div>
              </td>
              <td style="width:8px;"></td>
              <td style="background:#F8F8FA;border-radius:10px;padding:10px 12px;text-align:center;">
                <div style="font-size:20px;font-weight:700;color:#1C1C1E;">{{ $totalPatients }}</div>
                <div style="font-size:11px;color:#6E6E73;text-transform:uppercase;letter-spacing:0.4px;font-weight:600;">Patients</div>
              </td>
              <td style="width:8px;"></td>
              <td style="background:#F8F8FA;border-radius:10px;padding:10px 12px;text-align:center;">
                <div style="font-size:20px;font-weight:700;color:#1C1C1E;">{{ $totalVitals }}</div>
                <div style="font-size:11px;color:#6E6E73;text-transform:uppercase;letter-spacing:0.4px;font-weight:600;">Constantes</div>
              </td>
              <td style="width:8px;"></td>
              <td style="background:#F8F8FA;border-radius:10px;padding:10px 12px;text-align:center;">
                <div style="font-size:20px;font-weight:700;color:#1C1C1E;">{{ $totalChecks }}</div>
                <div style="font-size:11px;color:#6E6E73;text-transform:uppercase;letter-spacing:0.4px;font-weight:600;">Checklist</div>
              </td>
            </tr>
          </table>
        </td></tr>

        {{-- Rooms --}}
        @foreach($rooms as $room)
          <tr><td style="padding:24px 28px 0 28px;">
            <div style="font-size:11px;font-weight:700;color:#7B61FF;letter-spacing:0.5px;text-transform:uppercase;">Chambre</div>
            <div style="font-size:22px;font-weight:700;color:#1C1C1E;letter-spacing:-0.5px;">{{ $room['number'] ?? '?' }}</div>
          </td></tr>

          @foreach(($room['patients'] ?? []) as $p)
            <tr><td style="padding:14px 28px 0 28px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FAFAFB;border:1px solid #ECECEF;border-radius:12px;">
                <tr><td style="padding:14px 16px;">
                  <div style="font-size:16px;font-weight:700;color:#1C1C1E;">{{ $p['name'] ?? 'Patient' }}</div>
                  <div style="font-size:13px;color:#6E6E73;margin-top:2px;">
                    @if(!empty($p['age']))
                      {{ $p['age'] }} ans
                    @endif
                    @if(!empty($p['gender']))
                      &middot; {{ strtoupper(substr($p['gender'],0,1)) }}
                    @endif
                  </div>
                  @if(!empty($p['diagnosis']))
                    <div style="font-size:14px;color:#1C1C1E;margin-top:8px;line-height:1.45;">
                      {{ $p['diagnosis'] }}
                    </div>
                  @endif

                  @if(!empty($p['vitals']))
                    <div style="margin-top:14px;">
                      <div style="font-size:11px;font-weight:700;color:#6E6E73;letter-spacing:0.4px;text-transform:uppercase;margin-bottom:6px;">Dernières constantes</div>
                      @php $latest = $p['vitals'][0] ?? null; @endphp
                      @if($latest)
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:13px;color:#1C1C1E;">
                          @foreach([
                            'Température' => isset($latest['temperature']) ? $latest['temperature'].' °C' : null,
                            'TA'          => $latest['blood_pressure'] ?? null,
                            'FC'          => isset($latest['heart_rate']) ? $latest['heart_rate'].' bpm' : null,
                            'SpO₂'        => isset($latest['oxygen_saturation']) ? $latest['oxygen_saturation'].' %' : null,
                            'FR'          => isset($latest['respiratory_rate']) ? $latest['respiratory_rate'].' /min' : null,
                            'Glycémie'    => isset($latest['blood_glucose']) ? $latest['blood_glucose'].' g/L' : null,
                            'Douleur'     => isset($latest['pain_level']) ? $latest['pain_level'].'/10' : null,
                            'Poids'       => isset($latest['weight']) ? $latest['weight'].' kg' : null,
                          ] as $label => $value)
                            @if($value !== null)
                              <tr>
                                <td style="padding:4px 0;color:#6E6E73;width:120px;">{{ $label }}</td>
                                <td style="padding:4px 0;font-weight:600;">{{ $value }}</td>
                              </tr>
                            @endif
                          @endforeach
                        </table>
                      @endif
                    </div>
                  @endif

                  @if(!empty($p['checklist']))
                    <div style="margin-top:14px;">
                      <div style="font-size:11px;font-weight:700;color:#6E6E73;letter-spacing:0.4px;text-transform:uppercase;margin-bottom:6px;">Checklist</div>
                      @foreach($p['checklist'] as $c)
                        <div style="font-size:13px;color:#1C1C1E;padding:3px 0;">
                          <span style="color:{{ $c['is_done'] ? '#34C759' : '#9F9FA6' }};font-weight:700;">{{ $c['is_done'] ? '✓' : '○' }}</span>
                          <span style="margin-left:6px;{{ $c['is_done'] ? 'text-decoration:line-through;color:#9F9FA6;' : '' }}">{{ $c['title'] }}</span>
                          @if(!empty($c['due_label']))
                            <span style="color:#7B61FF;font-weight:600;margin-left:6px;">{{ $c['due_label'] }}</span>
                          @endif
                          @if(($c['priority'] ?? 'normal') === 'urgent')
                            <span style="background:#FFE4E6;color:#D7263D;font-size:10px;font-weight:700;padding:2px 6px;border-radius:8px;margin-left:6px;text-transform:uppercase;">Urgent</span>
                          @elseif(($c['priority'] ?? 'normal') === 'important')
                            <span style="background:#EFEAFF;color:#7B61FF;font-size:10px;font-weight:700;padding:2px 6px;border-radius:8px;margin-left:6px;text-transform:uppercase;">Important</span>
                          @endif
                        </div>
                      @endforeach
                    </div>
                  @endif
                </td></tr>
              </table>
            </td></tr>
          @endforeach
        @endforeach

        <tr><td style="padding:28px 28px 28px 28px;color:#9F9FA6;font-size:11px;line-height:1.5;border-top:1px solid #ECECEF;margin-top:28px;">
          Transmission générée par Lisa. Les notes personnelles ne sont jamais partagées dans les transmissions.
          <br/>Lisa reste une aide à l'organisation : les décisions cliniques sont sous responsabilité humaine.
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
