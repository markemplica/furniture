import './bootstrap';
import 'bootstrap';
import { initHomeCarousel } from './home-carousel';

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHomeCarousel, { once: true });
} else {
    initHomeCarousel();
}
