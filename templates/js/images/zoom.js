/**
 * Vanilla JS Image Zoom - works on desktop and mobile
 * Supports mouse wheel, click-drag, pinch-zoom, and touch-drag
 *
 * Wrapped in IIFE to prevent variable conflicts when loaded multiple times
 */

(function() {
    'use strict';

    // Zoom state variables
    let scale = 1;
    let translateX = 0;
    let translateY = 0;
    let isDragging = false;
    let startX = 0;
    let startY = 0;

    // Get elements
    const img = document.getElementById('zoomable-image');
    const container = document.getElementById('zoom-container');

    if (!img || !container) {
        console.error('Zoom elements not found');
        return;
    }

    initializeZoom();

    function initializeZoom() {
        // Mouse wheel zoom
        container.addEventListener('wheel', handleMouseWheel, { passive: false });

        // Mouse drag
        img.addEventListener('mousedown', handleMouseDown);
        document.addEventListener('mousemove', handleMouseMove);
        document.addEventListener('mouseup', handleMouseUp);

        // Touch pinch zoom and drag
        container.addEventListener('touchstart', handleTouchStart, { passive: false });
        container.addEventListener('touchmove', handleTouchMove, { passive: false });
        container.addEventListener('touchend', handleTouchEnd);

        // Double-tap to zoom (mobile)
        let lastTap = 0;
        img.addEventListener('touchend', function(e) {
            const currentTime = new Date().getTime();
            const tapLength = currentTime - lastTap;
            if (tapLength < 300 && tapLength > 0) {
                e.preventDefault();
                if (scale === 1) {
                    scale = 2.5;
                } else {
                    resetZoom();
                }
                updateTransform();
            }
            lastTap = currentTime;
        });
    }

    function updateTransform() {
        img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
        img.style.cursor = scale > 1 ? 'grab' : 'default';
    }

    // Expose zoom functions globally so buttons can call them
    window.zoomIn = function() {
        scale = Math.min(scale * 1.3, 5);
        updateTransform();
    };

    window.zoomOut = function() {
        scale = Math.max(scale / 1.3, 1);
        if (scale === 1) {
            translateX = 0;
            translateY = 0;
        }
        updateTransform();
    };

    window.resetZoom = function() {
        scale = 1;
        translateX = 0;
        translateY = 0;
        updateTransform();
    };

    // Mouse wheel zoom handler
    function handleMouseWheel(e) {
        e.preventDefault();
        const delta = e.deltaY > 0 ? 0.9 : 1.1;
        const newScale = Math.min(Math.max(scale * delta, 1), 5);

        if (newScale !== scale) {
            scale = newScale;
            if (scale === 1) {
                translateX = 0;
                translateY = 0;
            }
            updateTransform();
        }
    }

    // Mouse drag handlers
    function handleMouseDown(e) {
        if (scale > 1) {
            isDragging = true;
            startX = e.clientX - translateX;
            startY = e.clientY - translateY;
            img.style.cursor = 'grabbing';
            e.preventDefault();
        }
    }

    function handleMouseMove(e) {
        if (isDragging) {
            translateX = e.clientX - startX;
            translateY = e.clientY - startY;
            updateTransform();
        }
    }

    function handleMouseUp() {
        if (isDragging) {
            isDragging = false;
            img.style.cursor = scale > 1 ? 'grab' : 'default';
        }
    }

    // Touch handlers
    let initialDistance = 0;
    let initialScale = 1;

    function handleTouchStart(e) {
        if (e.touches.length === 2) {
            e.preventDefault();
            initialDistance = getTouchDistance(e.touches);
            initialScale = scale;
        } else if (e.touches.length === 1 && scale > 1) {
            isDragging = true;
            startX = e.touches[0].clientX - translateX;
            startY = e.touches[0].clientY - translateY;
        }
    }

    function handleTouchMove(e) {
        if (e.touches.length === 2) {
            e.preventDefault();
            const currentDistance = getTouchDistance(e.touches);
            const newScale = Math.min(Math.max(initialScale * (currentDistance / initialDistance), 1), 5);
            scale = newScale;
            if (scale === 1) {
                translateX = 0;
                translateY = 0;
            }
            updateTransform();
        } else if (e.touches.length === 1 && isDragging) {
            e.preventDefault();
            translateX = e.touches[0].clientX - startX;
            translateY = e.touches[0].clientY - startY;
            updateTransform();
        }
    }

    function handleTouchEnd(e) {
        if (e.touches.length === 0) {
            isDragging = false;
        }
    }

    function getTouchDistance(touches) {
        const dx = touches[0].clientX - touches[1].clientX;
        const dy = touches[0].clientY - touches[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }

})(); // End of IIFE

