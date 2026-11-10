import { useEffect, useRef } from "react";

interface Star {
  x: number;
  y: number;
  r: number;
  base: number;
  tw: number;
  sp: number;
  gold: boolean;
}

/** Ciel étoilé léger sur canvas, respecte prefers-reduced-motion. */
export default function Starfield() {
  const ref = useRef<HTMLCanvasElement | null>(null);

  useEffect(() => {
    const canvas = ref.current;
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const reduce = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
    const dpr = window.devicePixelRatio || 1;
    let w = 0;
    let h = 0;
    let stars: Star[] = [];
    let raf = 0;

    const resize = () => {
      w = canvas.width = window.innerWidth * dpr;
      h = canvas.height = window.innerHeight * dpr;
      canvas.style.width = `${window.innerWidth}px`;
      canvas.style.height = `${window.innerHeight}px`;
      const count = Math.min(150, Math.floor((window.innerWidth * window.innerHeight) / 9000));
      stars = Array.from({ length: count }, () => ({
        x: Math.random() * w,
        y: Math.random() * h * 0.82,
        r: (Math.random() * 1.1 + 0.2) * dpr,
        base: Math.random() * 0.5 + 0.25,
        tw: Math.random() * Math.PI * 2,
        sp: Math.random() * 0.9 + 0.25,
        gold: Math.random() < 0.16,
      }));
    };

    const draw = (t: number) => {
      ctx.clearRect(0, 0, w, h);
      for (const s of stars) {
        const a = reduce ? s.base : s.base + Math.sin(t * 0.001 * s.sp + s.tw) * 0.28;
        ctx.globalAlpha = Math.max(0, Math.min(1, a));
        ctx.fillStyle = s.gold ? "#f4dd9a" : "#dfe6ff";
        ctx.beginPath();
        ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.globalAlpha = 1;
      if (!reduce) raf = requestAnimationFrame(draw);
    };

    const onResize = () => {
      cancelAnimationFrame(raf);
      resize();
      if (reduce) draw(0);
      else raf = requestAnimationFrame(draw);
    };

    resize();
    if (reduce) draw(0);
    else raf = requestAnimationFrame(draw);
    window.addEventListener("resize", onResize);

    return () => {
      cancelAnimationFrame(raf);
      window.removeEventListener("resize", onResize);
    };
  }, []);

  return <canvas ref={ref} className="starfield" aria-hidden="true" />;
}
