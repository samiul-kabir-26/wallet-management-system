@extends('dev.layout')

@section('title', 'OTP codes')

@section('content')
    <p class="muted">Latest 200 codes, newest first. Refresh after requesting a new code.</p>
    <div class="wrap">
        <table>
            <thead>
            <tr><th>#</th><th>Code</th><th>Purpose</th><th>Account</th><th>Sent to</th><th>Status</th><th>Attempts</th><th>Created</th><th>Expires</th></tr>
            </thead>
            <tbody>
            @forelse ($otps as $otp)
                <tr>
                    <td class="muted">{{ $otp['id'] }}</td>
                    <td><code>{{ $otp['code'] }}</code></td>
                    <td>{{ $otp['purpose'] }}</td>
                    <td>{{ $otp['user'] }}</td>
                    <td>{{ $otp['sent_to'] }}</td>
                    <td><span class="badge {{ $otp['status'] }}">{{ $otp['status'] }}</span></td>
                    <td>{{ $otp['attempts'] }}</td>
                    <td class="muted">{{ $otp['created_at']?->format('Y-m-d H:i:s') }} UTC</td>
                    <td class="muted">{{ $otp['expires_at']?->format('Y-m-d H:i:s') }} UTC</td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">No OTP codes yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
