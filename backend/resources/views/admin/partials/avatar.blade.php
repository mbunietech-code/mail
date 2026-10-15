@php($seed = abs(crc32(strtolower((string) ($email ?? $name ?? '')))) % 6 + 1)
<span class="avatar c{{ $seed }} {{ $size ?? '' }}" aria-hidden="true">{{ mb_substr(trim((string) ($name ?? '')) ?: (string) ($email ?? '?'), 0, 1) }}</span>
