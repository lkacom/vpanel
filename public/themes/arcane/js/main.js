particlesJS("particles-js", {
    particles: {
        number: { value: 50, density: { enable: true, value_area: 800 } },
        color: { value: "#00FFC2" },
        shape: { type: "triangle", stroke: { width: 0, color: "#000000" } },
        opacity: { value: 0.3, random: true, anim: { enable: false } },
        size: { value: 4, random: true, anim: { enable: false } },
        line_linked: { enable: true, distance: 150, color: "#7B2BFF", opacity: 0.1, width: 1 },
        move: { enable: true, speed: 1.5, direction: "none", random: true, straight: false, out_mode: "out", bounce: false }
    },
    interactivity: {
        detect_on: "canvas",
        events: { onhover: { enable: true, mode: "grab" }, onclick: { enable: true, mode: "push" }, resize: true },
        modes: { grab: { distance: 140, line_opacity: 0.3 }, push: { particles_nb: 4 } }
    },
    retina_detect: true
});
