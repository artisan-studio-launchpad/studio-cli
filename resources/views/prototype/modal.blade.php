<div
    id="studio-prototype"
    style="position: fixed; inset: 0; z-index: 2147483000; display: flex; align-items: center; justify-content: center; padding: 3vh 3vw; background: rgba(2, 6, 23, 0.72); backdrop-filter: blur(4px);"
>
    <style>
        #studio-prototype .slidewire-controls,
        #studio-prototype .slidewire-progress {
            display: none !important;
        }
    </style>
    <div
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Prototype') }}"
        style="position: relative; display: flex; flex-direction: column; width: 100%; max-width: 1400px; height: 100%; max-height: 880px; overflow: hidden; border-radius: 16px; background: #05070d; box-shadow: 0 30px 80px rgba(0, 0, 0, 0.6);"
    >
        <a
            href="{{ $close }}"
            aria-label="{{ __('Close the prototype') }}"
            style="position: absolute; top: 12px; right: 14px; z-index: 10; display: grid; place-items: center; width: 32px; height: 32px; border-radius: 9999px; background: rgba(255, 255, 255, 0.08); color: #fff; font: 600 16px/1 system-ui, sans-serif; text-decoration: none;"
        >✕</a>
        <div style="display: flex; flex-direction: column; flex: 1; min-height: 0;">{!! $prototype !!}</div>
    </div>
    <script>
        document.addEventListener('keydown', (event) => event.key === 'Escape' && (window.location.href = @js($close)));
    </script>
</div>
