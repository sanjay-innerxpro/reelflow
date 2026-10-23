<!DOCTYPE html>
<html lang="en" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · Your reels, posted on autopilot</title>
    <meta name="description" content="Upload reels in bulk, get AI-written captions and publish to Facebook and Instagram on a schedule, through the official Meta APIs.">
    <x-theme-script />
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full">
    @php
        $features = [
            ['upload', 'Bulk upload', 'Drop a ZIP of up to 20 reels at once, or import a whole Google Drive folder. Duplicates are recognised automatically.'],
            ['sparkles', 'AI captions', 'Every video gets a caption and hashtags written for Facebook and Instagram. Edit, approve, or let it run.'],
            ['calendar', 'Smart scheduling', 'Pick your posting times once. ReelFlow fills the calendar, spaces posts out and never posts a video twice.'],
            ['shield', 'Official APIs only', 'Publishing uses Meta\'s official Graph API with your permission. No passwords stored, no browser bots.'],
            ['refresh', 'Reliable publishing', 'Uploads resume after interruptions, failed posts retry on their own, and you are told when something needs you.'],
            ['chart', 'Clear overview', 'See what is scheduled, what went out today and what needs attention, all in one dashboard.'],
        ];
    @endphp

    <header class="sticky top-0 z-30 border-b border-slate-200/70 bg-white/80 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
            <a href="{{ url('/') }}" class="flex items-center gap-2.5">
                <span class="brand-gradient flex h-9 w-9 items-center justify-center rounded-xl text-on-color shadow-(--shadow-lift)"><x-icon name="play" class="h-5 w-5" /></span>
                <span class="text-[15px] font-semibold tracking-tight text-slate-900">{{ config('app.name') }}</span>
            </a>
            <nav class="flex items-center gap-2">
                <a href="#features" class="btn btn-ghost hidden sm:inline-flex">Features</a>
                <a href="#how" class="btn btn-ghost hidden sm:inline-flex">How it works</a>
                <a href="{{ route('login') }}" class="btn">Sign in</a>
            </nav>
        </div>
    </header>

    <main>
        <section class="relative overflow-hidden">
            <div class="brand-gradient pointer-events-none absolute -top-40 left-1/2 h-[480px] w-[900px] -translate-x-1/2 rounded-full opacity-[0.12] blur-3xl"></div>
            <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
                <div>
                    <span class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700"><x-icon name="sparkles" class="h-3.5 w-3.5" /> Facebook & Instagram Reels automation</span>
                    <h1 class="mt-5 text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">Your reels, posted <span class="bg-linear-to-r from-indigo-600 to-fuchsia-500 bg-clip-text text-transparent">on autopilot.</span></h1>
                    <p class="mt-5 max-w-xl text-lg leading-relaxed text-slate-600">Upload a batch of videos once. {{ config('app.name') }} writes the captions, plans the schedule and publishes to your Facebook Page and Instagram, every day, without you.</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        @if($contact)
                            <a href="mailto:{{ $contact }}?subject={{ rawurlencode('Invite request for '.config('app.name')) }}" class="btn btn-primary h-11 px-5 text-base">Request an invite <x-icon name="arrow-right" class="h-4 w-4" /></a>
                        @endif
                        <a href="{{ route('login') }}" class="btn h-11 px-5 text-base">Sign in</a>
                    </div>
                    <p class="mt-4 text-sm text-slate-500">Invite-only. Free while in early access.</p>
                </div>

                <div class="relative mx-auto w-full max-w-[290px]">
                    <div class="absolute -inset-6 rounded-[3rem] bg-linear-to-br from-indigo-500/20 to-fuchsia-500/20 blur-2xl"></div>
                    <div class="relative rounded-[2.6rem] bg-slate-900 p-2.5 shadow-2xl ring-1 ring-slate-700/50 dark:bg-black">
                        <div class="brand-gradient relative aspect-[9/16] overflow-hidden rounded-[2.1rem]">
                            <div class="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgb(255_255_255/0.35),transparent_45%)]"></div>
                            <div class="absolute top-3 left-1/2 h-1.5 w-16 -translate-x-1/2 rounded-full bg-white/40"></div>
                            <div class="absolute inset-0 flex items-center justify-center"><span class="flex h-16 w-16 items-center justify-center rounded-full bg-white/25 text-on-color backdrop-blur"><x-icon name="play" class="h-8 w-8" /></span></div>
                            <div class="absolute right-3 bottom-24 flex flex-col gap-4 text-on-color"><x-icon name="heart" class="h-6 w-6" /><x-icon name="chat" class="h-6 w-6" /><x-icon name="send" class="h-6 w-6" /></div>
                            <div class="absolute inset-x-0 bottom-0 bg-linear-to-t from-black/70 to-transparent px-4 pt-10 pb-6 text-[12px] leading-snug text-on-color">
                                <p class="font-semibold">Your Page</p>
                                <p class="mt-1">Three tips to make every leg day count. Save this for your next workout!</p>
                                <p class="mt-1 font-medium opacity-90">#fitness #reels #workout</p>
                            </div>
                        </div>
                    </div>
                    <div class="absolute -right-6 top-16 hidden rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-(--shadow-lift) sm:block">
                        <div class="flex items-center gap-2 font-semibold text-slate-900"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-on-color"><x-icon name="check" class="h-3 w-3" /></span> Published</div>
                        <div class="mt-0.5 text-slate-500">Facebook Reels · 13:30</div>
                    </div>
                    <div class="absolute -left-8 bottom-24 hidden rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs shadow-(--shadow-lift) sm:block">
                        <div class="flex items-center gap-2 font-semibold text-slate-900"><x-icon name="sparkles" class="h-4 w-4 text-indigo-600" /> Caption written</div>
                        <div class="mt-0.5 text-slate-500">by AI in 4 seconds</div>
                    </div>
                </div>
            </div>
        </section>

        <section id="features" class="border-t border-slate-200/70 bg-white py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="max-w-2xl">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">Everything between “I have videos” and “they're live”.</h2>
                    <p class="mt-3 text-slate-600">No spreadsheets, no copy-pasting captions, no alarms to post at the right time.</p>
                </div>
                <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($features as [$icon, $title, $text])
                        <div class="card card-body">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600"><x-icon :name="$icon" class="h-5 w-5" /></span>
                            <h3 class="mt-4 font-semibold text-slate-900">{{ $title }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="how" class="py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <h2 class="text-3xl font-semibold tracking-tight text-slate-900">How it works</h2>
                <ol class="mt-10 grid gap-6 md:grid-cols-3">
                    @foreach([['Connect', 'Sign in with Facebook and choose the Pages and Instagram accounts to post to.'], ['Upload', 'Drop a ZIP of reels. Captions and hashtags are written for each one.'], ['Relax', 'Set your daily posting times. Reels go out on schedule; you get a daily summary.']] as $i => [$title, $text])
                        <li class="relative rounded-2xl border border-slate-200 bg-white p-6">
                            <span class="brand-gradient flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold text-on-color">{{ $i + 1 }}</span>
                            <h3 class="mt-4 font-semibold text-slate-900">{{ $title }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ $text }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        <section class="px-4 pb-20 sm:px-6">
            <div class="brand-gradient relative mx-auto max-w-6xl overflow-hidden rounded-3xl px-6 py-12 text-center text-on-color sm:px-12">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_0%,rgb(255_255_255/0.25),transparent_50%)]"></div>
                <h2 class="relative text-3xl font-semibold tracking-tight">Ready to put your reels on autopilot?</h2>
                <p class="relative mt-3 opacity-90">{{ config('app.name') }} is invite-only during early access.</p>
                <div class="relative mt-7 flex flex-wrap justify-center gap-3">
                    @if($contact)
                        <a href="mailto:{{ $contact }}?subject={{ rawurlencode('Invite request for '.config('app.name')) }}" class="btn h-11 border-transparent bg-white px-5 text-base text-indigo-700 hover:bg-white/90">Request an invite</a>
                    @endif
                    <a href="{{ route('login') }}" class="btn h-11 border-white/40 bg-white/10 px-5 text-base text-on-color hover:bg-white/20">Sign in</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-200/70 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-8 text-sm text-slate-500 sm:px-6">
            <span>© {{ date('Y') }} {{ config('reelflow.operator_name') ?: config('app.name') }}</span>
            <nav class="flex gap-5">
                <a href="{{ route('legal.privacy') }}" class="hover:text-slate-900">Privacy</a>
                <a href="{{ route('legal.terms') }}" class="hover:text-slate-900">Terms</a>
                <a href="{{ route('legal.data-deletion') }}" class="hover:text-slate-900">Data deletion</a>
            </nav>
        </div>
    </footer>
</body>
</html>
