@extends('dev.layout')

@section('title', 'Email links')

@section('content')
    <p class="muted">Staff invitations and PIN reset links read from storage/logs/laravel.log (MAIL_MAILER=log), newest first. "Open" goes to the web page even for emails sent before the pages existed.</p>
    <div class="wrap">
        <table>
            <thead>
            <tr><th>Sent</th><th>Type</th><th>To</th><th>Temporary password</th><th>Link</th><th>Expires</th></tr>
            </thead>
            <tbody>
            @forelse ($links as $link)
                <tr>
                    <td class="muted">{{ $link['logged_at'] }}</td>
                    <td>{{ $link['kind'] }}</td>
                    <td>{{ $link['to'] ?? '—' }}</td>
                    <td>@if ($link['temporary_password'])<code>{{ $link['temporary_password'] }}</code>@else<span class="muted">—</span>@endif</td>
                    <td>
                        @if ($link['expired'])
                            <span class="badge expired">expired</span>
                        @else
                            <a class="open" href="{{ $link['page_url'] }}" target="_blank" rel="noopener">Open</a>
                        @endif
                        <div class="muted url" title="{{ $link['emailed_url'] }}">{{ $link['emailed_url'] }}</div>
                    </td>
                    <td class="muted">{{ $link['expires_at']?->format('Y-m-d H:i:s') }} UTC</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No invitation or PIN reset emails in the log.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
