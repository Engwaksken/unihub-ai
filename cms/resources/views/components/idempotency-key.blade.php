{{-- A fresh Idempotency-Key for each rendered form: a double submit reuses it. --}}
<input type="hidden" name="_idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
