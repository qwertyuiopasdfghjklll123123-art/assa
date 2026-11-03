#!/bin/sh
# Arabic voice-over lines for the Fast Grand promo (offline espeak-ng). Usage: sh voice.sh <outDir>
OUT=${1:-vo}; mkdir -p "$OUT"
V="ar+m3"; S=${SPEED:-270}; P=40
say() { espeak-ng -v "$V" -s $S -p $P -g 1 -w "$OUT/$1.wav" "$2"; }
say 1 "فاسْت جْرانْد، شَرِكَةٌ عِراقِيَّةٌ لِصِناعَةِ التَّطْبِيقات."
say 2 "جَوْدَةٌ لا تُقارَن، بِسِعْرٍ مُناسِب، وَتَنْفِيذٍ سَرِيع."
say 3 "تَطْبِيقٌ كامِل، أَنْدْرُويد وَآيْفون، بِتِسْعَةٍ وَتِسْعِين دُولاراً."
say 4 "مَوْقِعُ إِدارَةٍ وَمَبِيعات، بِخَمْسَةٍ وَخَمْسِين."
say 5 "وَمَوْقِعٌ تَعْرِيفِيّ، بِخَمْسَةٍ وَثَلاثِين."
say 6 "تَواصَلْ مَعَنا الآن!"
for f in "$OUT"/*.wav; do python3 - "$f" <<'PY'
import sys,wave,numpy as np
w=wave.open(sys.argv[1]);x=np.frombuffer(w.readframes(w.getnframes()),np.int16).astype(float);sr=w.getframerate()
nz=np.nonzero(np.abs(x)>300)[0];print(sys.argv[1].split('/')[-1], round((nz[-1]-nz[0])/sr,2))
PY
done
