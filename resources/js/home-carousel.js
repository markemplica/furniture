import { Carousel } from 'bootstrap';

export function initHomeCarousel() {
    const element = document.querySelector('[data-home-carousel]');
    if (!element || element.dataset.playbackInitialized || element.querySelectorAll('.carousel-item').length < 2) return;
    element.dataset.playbackInitialized = 'true';

    // Keep Bootstrap's automatic data-API cycling disabled; this state owns rotation.
    const carousel = Carousel.getOrCreateInstance(element, { interval: 3000, ride: false, pause: false });
    const inner = element.querySelector('.carousel-inner');

    const sync = () => {
        const playing = !document.hidden;
        if (playing) carousel.cycle();
        else carousel.pause();
        element.dataset.carouselPlaying = String(playing);
        inner.setAttribute('aria-live', playing ? 'off' : 'polite');

    };

    document.addEventListener('visibilitychange', sync);
    sync();
}
