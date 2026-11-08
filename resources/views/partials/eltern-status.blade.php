{{-- Status-Icons hinter einem Elternteil: Konto, Terminseite besucht, Mailadresse. Braucht $e, $besuche, $selbstGebucht. --}}
@php
    $konto = $e->password !== null || $e->microsoft_angemeldet_am !== null;
    $besuch = $besuche[$e->id] ?? null;
@endphp
<span class="ml-0.5 inline-flex gap-0.5 align-middle text-base leading-none">
    @if ($konto)
        <i class="bx bx-user-check text-green-600" title="Konto eingerichtet (Passwort gesetzt)"></i>
    @else
        <i class="bx bx-user-x text-gray-400" title="Konto noch nicht eingerichtet (kein Passwort gesetzt)"></i>
    @endif
    @if ($besuch)
        <i class="bx bx-show text-green-600" title="Terminseite besucht, zuletzt {{ \Illuminate\Support\Carbon::parse($besuch)->format('d.m.Y H:i') }}"></i>
    @elseif ($selbstGebucht->has($e->id))
        <i class="bx bx-show text-green-600" title="Terminseite besucht (hat selbst gebucht)"></i>
    @else
        <i class="bx bx-hide text-gray-400" title="Terminseite noch nicht besucht"></i>
    @endif
    @if (blank($e->email))
        <i class="bx bx-envelope text-red-600" title="Keine Mailadresse hinterlegt – bekommt keine Einladung"></i>
    @endif
</span>