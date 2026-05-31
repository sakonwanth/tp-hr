<!-- Holiday detail modal -->
<div id="tp-holiday-detail-modal"
     class="tp-native-modal fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-5 overflow-y-auto overscroll-contain pt-[env(safe-area-inset-top,0px)] pb-[env(safe-area-inset-bottom,0px)]"
     role="dialog"
     aria-modal="true"
     aria-labelledby="tp-holiday-detail-title">
    <div class="native-card tp-native-card w-full max-w-md my-auto max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain overflow-x-hidden p-6 pb-[calc(env(safe-area-inset-bottom,0px)+1.5rem)]">
        <div class="flex items-start justify-between gap-3 mb-5">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold uppercase tracking-wider text-violet-300/90 mb-1" id="tp-holiday-detail-type"></p>
                <h3 class="text-xl font-bold text-white leading-snug break-words" id="tp-holiday-detail-title"></h3>
                <p class="text-white/45 text-sm mt-1 break-words hidden" id="tp-holiday-detail-name-en"></p>
            </div>
            <button type="button"
                    onclick="tpHolidaysCloseDetail()"
                    class="min-h-[48px] min-w-[48px] inline-flex shrink-0 items-center justify-center text-white/60 hover:text-white hover:bg-white/10 rounded-[var(--tp-ios-card-radius)] touch-manipulation"
                    aria-label="ปิด">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <div class="tp-ios-attendance-panel p-5 mb-4">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 shrink-0 rounded-[var(--tp-ios-card-radius)] bg-violet-500/20 ring-1 ring-violet-400/35 flex flex-col items-center justify-center text-white" id="tp-holiday-detail-date-chip">
                    <span class="text-[11px] font-medium opacity-85" id="tp-holiday-detail-mon"></span>
                    <span class="text-2xl font-bold tabular-nums leading-none" id="tp-holiday-detail-day"></span>
                </div>
                <div class="min-w-0">
                    <p class="text-white font-medium" id="tp-holiday-detail-date-th"></p>
                    <p class="text-white/55 text-sm mt-1" id="tp-holiday-detail-dow"></p>
                    <p class="text-violet-200/90 text-sm font-semibold mt-2 hidden" id="tp-holiday-detail-countdown"></p>
                </div>
            </div>
        </div>

        <div class="tp-native-form-group mb-0 hidden" id="tp-holiday-detail-desc-wrap">
            <p class="text-white/55 text-xs mb-1">รายละเอียด</p>
            <p class="text-white/80 text-sm leading-relaxed" id="tp-holiday-detail-desc"></p>
        </div>
    </div>
</div>
