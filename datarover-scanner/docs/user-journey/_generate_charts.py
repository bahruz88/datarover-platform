#!/usr/bin/env python3
"""Generate visual assets for the User Journey document."""

import os
from pathlib import Path
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import matplotlib.patches as mpatches
from matplotlib.patches import FancyBboxPatch, FancyArrowPatch
import numpy as np

OUT = Path(__file__).parent / "assets"
OUT.mkdir(exist_ok=True)

plt.rcParams["font.family"] = "Segoe UI"
plt.rcParams["axes.titlesize"] = 13
plt.rcParams["axes.labelsize"] = 11
plt.rcParams["xtick.labelsize"] = 9
plt.rcParams["ytick.labelsize"] = 9

BLUE = "#1E40AF"
BLUE_LT = "#60A5FA"
GREEN = "#10B981"
RED = "#EF4444"
AMBER = "#F59E0B"
GRAY = "#64748B"
BG = "#F8FAFC"


def emotion_curve():
    journeys = [
        ("1. Onboarding", [3, 3, 3, 2, 3, 4, 4, 2]),
        ("2. Data Source", [3, 3, 3, 4, 3, 4, 4, 4]),
        ("3. Qayda Yaratma", [4, 3, 4, 3, 4, 3, 4, 4, 4, 4]),
        ("4. Yoxlama İcrası", [4, 3, 3, 3, 4, 4, 4, 4, 2]),
        ("5. Avtomat Cədvəl", [3, 4, 4, 3, 4, 4, 3, 4, 3, 4]),
        ("6. Nəticə və Hesabat", [4, 3, 4, 4, 4, 4, 4, 4, 4, 3]),
        ("7. Məxfi Məlumat", [3, 2, 2, 3, 3, 4, 3, 4, 3, 2]),
    ]

    fig, axes = plt.subplots(4, 2, figsize=(11, 10), constrained_layout=True)
    fig.suptitle("Journey-lər üzrə İstifadəçi Emosiya Əyrisi (As-Is, v56)",
                 fontsize=15, fontweight="bold", color=BLUE)
    axes = axes.flatten()

    for idx, (name, emo) in enumerate(journeys):
        ax = axes[idx]
        x = list(range(1, len(emo) + 1))
        colors = [GREEN if e >= 4 else (AMBER if e == 3 else RED) for e in emo]
        ax.plot(x, emo, color=BLUE, linewidth=2, zorder=1)
        ax.scatter(x, emo, c=colors, s=80, zorder=2, edgecolors="white", linewidth=1.5)
        ax.set_ylim(0.5, 4.5)
        ax.set_xlim(0.5, len(emo) + 0.5)
        ax.set_yticks([1, 2, 3, 4])
        ax.set_yticklabels(["Narazı", "Gərgin", "Neytral", "Məmnun"], fontsize=8)
        ax.set_xticks(x)
        ax.set_title(name, fontsize=11, fontweight="bold", color="#1F2937")
        ax.grid(True, alpha=0.25)
        ax.set_facecolor(BG)
        ax.axhline(y=3, color=GRAY, linestyle=":", alpha=0.5, linewidth=0.8)

    axes[-1].axis("off")
    legend = [
        mpatches.Patch(color=GREEN, label="Məmnun"),
        mpatches.Patch(color=AMBER, label="Neytral"),
        mpatches.Patch(color=RED, label="Narazı / Gərgin"),
    ]
    axes[-1].legend(handles=legend, loc="center", fontsize=11, frameon=False)

    out = OUT / "01_emotion_curve.png"
    plt.savefig(out, dpi=150, bbox_inches="tight", facecolor="white")
    plt.close()
    print(f"  {out.name}")


def persona_journey_heatmap():
    personas = ["Aytən\n(Data Steward)", "Rəşad\n(Analitik)",
                "Elvin\n(Data Engineer)", "Nigar xanım\n(CDO)",
                "Sevinc\n(Compliance)"]
    journeys = ["1.Onboarding", "2.Data Source", "3.Qayda",
                "4.İcra", "5.Cədvəl", "6.Hesabat", "7.Məxfilik"]

    data = np.array([
        [2, 1, 3, 3, 1, 1, 1],
        [2, 0, 1, 1, 1, 3, 0],
        [2, 3, 1, 0, 3, 0, 0],
        [2, 0, 0, 0, 0, 3, 1],
        [2, 0, 0, 0, 0, 1, 3],
    ])

    fig, ax = plt.subplots(figsize=(10, 5.5))
    cmap = plt.cm.Blues
    im = ax.imshow(data, cmap=cmap, aspect="auto", vmin=0, vmax=3)

    ax.set_xticks(np.arange(len(journeys)))
    ax.set_yticks(np.arange(len(personas)))
    ax.set_xticklabels(journeys, rotation=25, ha="right")
    ax.set_yticklabels(personas)

    labels = {0: "—", 1: "İstifadəçi", 2: "Yardımçı", 3: "Aktor"}
    for i in range(len(personas)):
        for j in range(len(journeys)):
            v = data[i, j]
            txt_color = "white" if v >= 2 else "#1F2937"
            ax.text(j, i, labels[v], ha="center", va="center",
                    fontsize=9, color=txt_color,
                    fontweight="bold" if v == 3 else "normal")

    ax.set_title("Persona × Journey — Rol Matrisi",
                 fontsize=14, fontweight="bold", color=BLUE, pad=14)
    for spine in ax.spines.values():
        spine.set_visible(False)
    ax.tick_params(length=0)

    out = OUT / "02_persona_journey_matrix.png"
    plt.savefig(out, dpi=150, bbox_inches="tight", facecolor="white")
    plt.close()
    print(f"  {out.name}")


def pain_pareto():
    pains = [
        "Məxfi məlumat avtomatik\ntapılmır",
        "Kredensial açıq\nsaxlanılır",
        "Avtomat cədvəl ayrı\nautentifikasiyadadır",
        "Böyük kataloqda\nyavaşlama",
        "İlk giriş üçün\nbələdçi yoxdur",
        "PDF / icra hesabatı\nyoxdur",
        "Audit tarixçəsində\nfiltr məhduddur",
        "Lokalizasiya\nnatamamdır",
        "Daxili incident\nyoxdur",
        "Hazır qayda şablonu\nyoxdur",
    ]
    impact = [95, 88, 82, 70, 65, 62, 55, 50, 40, 35]
    priority = ["High", "High", "High", "Medium", "Medium",
                "Medium", "Medium", "Medium", "Low", "Low"]
    p_color = {"High": RED, "Medium": AMBER, "Low": "#94A3B8"}
    colors = [p_color[p] for p in priority]

    fig, ax = plt.subplots(figsize=(11, 6))
    bars = ax.barh(pains, impact, color=colors, edgecolor="white", linewidth=1.2)
    ax.invert_yaxis()
    ax.set_xlabel("Təsir balı (0–100)")
    ax.set_title("Top 10 Ağrı Nöqtəsi — Prioritet üzrə",
                 fontsize=14, fontweight="bold", color=BLUE, pad=12)
    ax.set_facecolor(BG)
    ax.grid(axis="x", alpha=0.3)

    for bar, val, pri in zip(bars, impact, priority):
        ax.text(val + 1.5, bar.get_y() + bar.get_height() / 2,
                f"{val} · {pri}", va="center", fontsize=9,
                color="#1F2937", fontweight="bold" if pri == "High" else "normal")

    legend = [mpatches.Patch(color=p_color[k], label=k) for k in ["High", "Medium", "Low"]]
    ax.legend(handles=legend, loc="lower right", frameon=False, fontsize=10)
    ax.set_xlim(0, 115)

    out = OUT / "03_pain_pareto.png"
    plt.savefig(out, dpi=150, bbox_inches="tight", facecolor="white")
    plt.close()
    print(f"  {out.name}")


def kpi_as_is_to_be():
    kpis = [
        "Qayda qurma\nvaxtı (dəq)",
        "IT-dən\nasılılıq (%)",
        "PII inventar\ntamlığı (%)",
        "Hesabat data source\ntapma vaxtı (dəq)",
        "Hesabatda\nyanlışlıq (ay)",
        "Audit cavab\nvaxtı (dəq)",
    ]
    as_is = [12, 100, 30, 30, 3.0, 180]
    to_be = [3, 20, 95, 2, 0.5, 10]

    as_is_norm = [v / max(max(as_is), max(to_be)) * 100 for v in as_is]
    to_be_norm = [v / max(max(as_is), max(to_be)) * 100 for v in to_be]

    fig, ax = plt.subplots(figsize=(11, 5.5))
    x = np.arange(len(kpis))
    w = 0.38

    b1 = ax.bar(x - w / 2, as_is_norm, w, label="As-Is (v56)", color="#CBD5E1", edgecolor="white")
    b2 = ax.bar(x + w / 2, to_be_norm, w, label="To-Be (v57+)", color=GREEN, edgecolor="white")

    for bar, v in zip(b1, as_is):
        ax.text(bar.get_x() + bar.get_width() / 2, bar.get_height() + 1.5,
                f"{v}", ha="center", fontsize=9, color="#475569")
    for bar, v in zip(b2, to_be):
        ax.text(bar.get_x() + bar.get_width() / 2, bar.get_height() + 1.5,
                f"{v}", ha="center", fontsize=9, color=GREEN, fontweight="bold")

    ax.set_xticks(x)
    ax.set_xticklabels(kpis, fontsize=9.5)
    ax.set_ylabel("Normallaşdırılmış bal")
    ax.set_title("KPI Müqayisəsi — As-Is vs To-Be",
                 fontsize=14, fontweight="bold", color=BLUE, pad=12)
    ax.legend(loc="upper right", frameon=False, fontsize=11)
    ax.set_facecolor(BG)
    ax.grid(axis="y", alpha=0.3)
    ax.set_ylim(0, 120)

    out = OUT / "04_kpi_as_is_to_be.png"
    plt.savefig(out, dpi=150, bbox_inches="tight", facecolor="white")
    plt.close()
    print(f"  {out.name}")


def journey_flow():
    steps = [
        ("1\nOnboarding", "Daxil ol,\ndashboard aç"),
        ("2\nData Source", "Mənbə qoş,\nkataloq tara"),
        ("3\nQayda", "Biznes qayda\nqur"),
        ("4\nİcra", "Yoxlamanı\nişə sal"),
        ("5\nCədvəl", "Avtomat\ncədvələ qoş"),
        ("6\nHesabat", "Nəticə, trend,\nixrac"),
        ("7\nMəxfilik", "PII qeydiyyatı,\naudit"),
    ]
    fig, ax = plt.subplots(figsize=(13, 3.6))
    ax.set_xlim(0, 14)
    ax.set_ylim(0, 4)
    ax.axis("off")

    colors_grad = ["#DBEAFE", "#BFDBFE", "#93C5FD", "#60A5FA", "#3B82F6", "#2563EB", "#1D4ED8"]

    for i, ((num, label), color) in enumerate(zip(steps, colors_grad)):
        x = 0.3 + i * 1.95
        box = FancyBboxPatch((x, 1.2), 1.6, 1.6,
                             boxstyle="round,pad=0.05,rounding_size=0.15",
                             facecolor=color, edgecolor="white", linewidth=2)
        ax.add_patch(box)
        txt_color = "white" if i >= 3 else "#1F2937"
        ax.text(x + 0.8, 2.3, num, ha="center", va="center",
                fontsize=14, fontweight="bold", color=txt_color)
        ax.text(x + 0.8, 1.65, label, ha="center", va="center",
                fontsize=8.5, color=txt_color)

        if i < len(steps) - 1:
            arrow = FancyArrowPatch((x + 1.65, 2.0), (x + 1.9, 2.0),
                                    arrowstyle="->", mutation_scale=18,
                                    color=GRAY, linewidth=2)
            ax.add_patch(arrow)

    ax.text(7, 3.5, "MIM — İstifadəçi Yolu (Ümumi Axın)",
            ha="center", fontsize=15, fontweight="bold", color=BLUE)
    ax.text(7, 0.4,
            "İlk girişdən məxfilik auditinə qədər — hər mərhələ özündən əvvəlkinin nəticəsinə söykənir",
            ha="center", fontsize=9.5, color=GRAY, style="italic")

    out = OUT / "05_journey_flow.png"
    plt.savefig(out, dpi=150, bbox_inches="tight", facecolor="white")
    plt.close()
    print(f"  {out.name}")


def persona_radar():
    categories = ["Texniki\nbacarıq", "İstifadə\ntezliyi", "Biznes\ntəsiri",
                  "Keyfiyyət\nməsuliyyəti", "İcra\nsürəti"]
    N = len(categories)
    angles = [n / N * 2 * np.pi for n in range(N)]
    angles += angles[:1]

    personas = [
        ("Aytən (Data Steward)",   [2, 5, 4, 5, 3], "#2563EB"),
        ("Rəşad (Analitik)",       [3, 4, 4, 3, 4], "#10B981"),
        ("Elvin (Data Engineer)",  [5, 3, 3, 2, 5], "#F59E0B"),
        ("Nigar xanım (CDO)",      [2, 2, 5, 4, 2], "#EF4444"),
        ("Sevinc (Compliance)",    [2, 2, 5, 5, 1], "#8B5CF6"),
    ]

    fig, ax = plt.subplots(figsize=(8, 7), subplot_kw=dict(polar=True))
    for name, vals, color in personas:
        values = vals + vals[:1]
        ax.plot(angles, values, linewidth=2, label=name, color=color)
        ax.fill(angles, values, alpha=0.12, color=color)

    ax.set_xticks(angles[:-1])
    ax.set_xticklabels(categories, fontsize=10)
    ax.set_yticks([1, 2, 3, 4, 5])
    ax.set_yticklabels(["1", "2", "3", "4", "5"], fontsize=8, color=GRAY)
    ax.set_ylim(0, 5)
    ax.set_title("Persona Profili — 5 Ölçü üzrə",
                 fontsize=14, fontweight="bold", color=BLUE, pad=25)
    ax.legend(loc="upper right", bbox_to_anchor=(1.35, 1.05), fontsize=9, frameon=False)
    ax.grid(alpha=0.3)

    out = OUT / "06_persona_radar.png"
    plt.savefig(out, dpi=150, bbox_inches="tight", facecolor="white")
    plt.close()
    print(f"  {out.name}")


def touchpoint_distribution():
    stages = ["Giriş və\nTanışlıq", "Konfiqurasiya", "Gündəlik\nİstifadə",
              "Avtomatlaşdırma", "Analiz və\nHesabat", "Uyğunluq\nAuditi"]
    current = [3, 5, 7, 4, 5, 3]
    target = [5, 6, 9, 8, 9, 7]

    fig, ax = plt.subplots(figsize=(10, 5))
    x = np.arange(len(stages))
    ax.fill_between(x, current, alpha=0.35, color=BLUE_LT, label="As-Is (v56)")
    ax.plot(x, current, color=BLUE, linewidth=2.5, marker="o", markersize=8)
    ax.fill_between(x, target, alpha=0.2, color=GREEN, label="To-Be (v57+)")
    ax.plot(x, target, color=GREEN, linewidth=2.5, marker="s", markersize=8, linestyle="--")

    ax.set_xticks(x)
    ax.set_xticklabels(stages, fontsize=10)
    ax.set_ylabel("Yetkinlik səviyyəsi (0–10)")
    ax.set_ylim(0, 10)
    ax.set_title("Mərhələ üzrə Yetkinlik — Cari vs Hədəf",
                 fontsize=14, fontweight="bold", color=BLUE, pad=12)
    ax.legend(loc="upper left", frameon=False, fontsize=11)
    ax.set_facecolor(BG)
    ax.grid(alpha=0.3)

    out = OUT / "07_maturity.png"
    plt.savefig(out, dpi=150, bbox_inches="tight", facecolor="white")
    plt.close()
    print(f"  {out.name}")


if __name__ == "__main__":
    print("Qrafiklər generasiya olunur...")
    emotion_curve()
    persona_journey_heatmap()
    pain_pareto()
    kpi_as_is_to_be()
    journey_flow()
    persona_radar()
    touchpoint_distribution()
    print(f"Hamısı {OUT}/ qovluğuna yazıldı.")
