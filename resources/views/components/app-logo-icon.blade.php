@php $gradientId = 'app-logo-gradient-'.uniqid(); @endphp

<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="none" {{ $attributes }}>
    <defs>
        <linearGradient id="{{ $gradientId }}" x1="40" y1="48" x2="216" y2="208" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#53D6E8" />
            <stop offset="1" stop-color="#7C6CF0" />
        </linearGradient>
    </defs>
    <g fill="url(#{{ $gradientId }})">
        <rect x="39" y="76" width="34" height="128" rx="11" />
        <rect x="87" y="52" width="34" height="128" rx="11" />
        <rect x="135" y="64" width="34" height="128" rx="11" />
        <rect x="183" y="88" width="34" height="128" rx="11" />
    </g>
</svg>
