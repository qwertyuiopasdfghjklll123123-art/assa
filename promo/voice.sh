#!/bin/sh
# Generates the Arabic voice-over lines (offline, espeak-ng) and prints their durations.
# Usage: sh voice.sh <outDir>
OUT=${1:-vo}; mkdir -p "$OUT"
V="ar+m3"; S=195; P=38
say() { espeak-ng -v "$V" -s $S -p $P -g 2 -w "$OUT/$1.wav" "$2"; }
say 1 "عَرْضُ الأُسْبُوعِ مِنْ نِينْجا!"
say 2 "خَصْمٌ خَمْسُونَ بِالمِئَة، عَلَى بَرْمَجَةِ المَوَاقِعِ وَالتَّطْبِيقَات."
say 3 "مَوْقِعٌ تَعْرِيفِيٌّ، بِخَمْسَةٍ وَثَلَاثِينَ دُولَاراً فَقَط!"
say 4 "تَطْبِيقُ جَوَّال، بِخَمْسَةٍ وَتِسْعِينَ دُولَاراً فَقَط!"
say 5 "وَمَتْجَرٌ إِلِكْتْرُونِيّ، بِتِسْعَةٍ وَأَرْبَعِينَ دُولَاراً!"
say 6 "العَرْضُ لِهَذَا الأُسْبُوعِ فَقَط. اُطْلُبِ الآن، مِنْ أَنَا نِينْجا دُوت كُوم!"
for f in "$OUT"/*.wav; do printf "%s %s\n" "$(basename $f)" "$(ffprobe -v error -show_entries format=duration -of csv=p=0 $f)"; done
