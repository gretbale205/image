"""Stacking metrics for logging & diagnostics."""
from __future__ import annotations

from dataclasses import dataclass, field


@dataclass
class StackingMetrics:
    frame_count: int = 0
    width: int = 0
    height: int = 0
    alignment_scores: list[float] = field(default_factory=list)
    alignment_mean: float = 0.0
    alignment_min: float = 0.0
    alignment_reference_index: int = 0
    method: str = ""
    processing_time_s: float = 0.0
    peak_ram_mb: float = 0.0

    def log_line(self) -> str:
        return (
            f"[stacking] frames={self.frame_count} "
            f"resolution={self.width}x{self.height} "
            f"method={self.method} "
            f"align_mean={self.alignment_mean:.3f} "
            f"align_min={self.alignment_min:.3f} "
            f"time={self.processing_time_s:.2f}s "
            f"peak_ram={self.peak_ram_mb:.0f}MB"
        )