<x-layouts.app :immersive="true">
<div class="football-landing text-white">
    <nav class="landing-nav" aria-label="Main navigation">
        <a href="{{ route('home') }}"><x-brand-logo class="w-48" /></a>
        <div><a href="{{ route('login') }}">Log in</a><a class="landing-button" href="{{ route('register') }}">Sign up</a></div>
    </nav>
    <main>
        <section class="landing-hero">
            <div>
                <p class="landing-eyebrow">Your world. Your team. Your call.</p>
                <h1>Call the play.<br>Watch it unfold.</h1>
                <p class="landing-intro">Build your own football world and take the sideline. Pick formations, call plays, and watch every snap come to life in a 3D stadium—all in your browser.</p>
                <div class="landing-actions"><a class="landing-button" href="{{ route('register') }}">Create your account</a><a href="{{ route('login') }}">Already playing? Log in →</a></div>
                <p class="landing-note">Human vs. CPU, head-to-head on one screen, or CPU vs. CPU.</p>
            </div>
            <div class="landing-field" aria-hidden="true">
                <div class="landing-score"><span>YOUR TEAM <b>21</b></span><span>Q4 · 01:08</span><span>VISITORS <b>17</b></span></div>
                <svg viewBox="0 0 500 330" role="presentation">
                    <rect x="10" y="25" width="480" height="270" rx="8" fill="#236142" stroke="#f8fafc" stroke-width="8"/>
                    <path d="M65 25V295M435 25V295" stroke="#fff" stroke-width="3"/>
                    <path d="M115 25V295M165 25V295M215 25V295M265 25V295M315 25V295M365 25V295M415 25V295" stroke="#fff" stroke-opacity=".45" stroke-width="2"/>
                    <path d="M240 25V295" stroke="#60a5fa" stroke-width="4"/>
                    <path d="M340 25V295" stroke="#fbbf24" stroke-width="4"/>
                    <path d="M225 65H300L390 42M225 252H320L370 205M200 150H270L305 180" fill="none" stroke="#fbbf24" stroke-width="4" stroke-linecap="round"/>
                    <g fill="#60a5fa" stroke="#e2e8f0" stroke-width="3"><circle cx="220" cy="120" r="8"/><circle cx="220" cy="140" r="8"/><circle cx="220" cy="160" r="8"/><circle cx="220" cy="180" r="8"/><circle cx="220" cy="200" r="8"/><circle cx="220" cy="65" r="8"/><circle cx="220" cy="252" r="8"/><circle cx="210" cy="225" r="8"/><circle cx="180" cy="160" r="8"/><circle cx="150" cy="180" r="8"/><circle cx="200" cy="95" r="8"/></g>
                    <g fill="#f87171" stroke="#fee2e2" stroke-width="3"><circle cx="255" cy="115" r="8"/><circle cx="255" cy="145" r="8"/><circle cx="255" cy="175" r="8"/><circle cx="255" cy="205" r="8"/><circle cx="285" cy="125" r="8"/><circle cx="285" cy="165" r="8"/><circle cx="285" cy="210" r="8"/><circle cx="265" cy="65" r="8"/><circle cx="265" cy="252" r="8"/><circle cx="355" cy="100" r="8"/><circle cx="355" cy="220" r="8"/></g>
                    <text x="250" y="320" fill="#94a3b8" font-size="12" text-anchor="middle" letter-spacing="3">PICK YOUR FORMATION · MAKE YOUR CALL</text>
                </svg>
            </div>
        </section>
        <section class="landing-features" aria-label="Game features">
            <article><span>01 / COACH</span><h2>Take control of the sideline</h2><p>Choose offense and defense, send players in motion, manage the clock, and adjust your depth chart during the game. Coach pick can help with the next call.</p></article>
            <article><span>02 / CREATE</span><h2>Make the league yours</h2><p>Start with fictional teams or build your own. Customize team colors, uniforms, logos, and stadiums inside independent worlds saved to your account.</p></article>
            <article><span>03 / RELIVE</span><h2>Every game has a story</h2><p>Follow the play log, check the box score, and replay the big moments. Short on time? Quick Sim a whole game and explore the results.</p></article>
        </section>
        <section class="landing-future"><div><p class="landing-eyebrow">Growing one snap at a time</p><h2>Exhibition football today.<br>A bigger football world ahead.</h2><p>Season play and Franchise mode are planned next. Start exploring the game with exhibitions and your own teams now.</p></div><a class="landing-button" href="{{ route('register') }}">Start your football world</a></section>
    </main>
    <footer>WebSports Football · Football simulation in your browser.</footer>
</div>
</x-layouts.app>
