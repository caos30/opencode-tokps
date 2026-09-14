#!/usr/bin/env python3
"""
tokps.py — Throughput retrospectivo (tokens/s) de los LLM usados por opencode.

Fuente: ~/.local/share/opencode/opencode.db (message + part).

Anatomía del span de un mensaje assistant (verificado empíricamente ago-2026):
  c0 = time.created (inicio request LLM)
  c0 → primer tool start = streaming LLM puro (TTFT + tokens output+reasoning)
  tool parts = ejecución de tools (bash/read/mcp...) DENTRO del span, al final
  c1 = time.completed (~100-200ms tras el último tool)
Consecuencia: tok/s limpio = (output+reasoning) / ventana_LLM_pura, donde
ventana = (primer_tool_start - c0) si hay tools, si no (c1 - c0).
El tiempo de tools se reporta aparte (tool_ms).

Uso:
  tokps.py                      -> resumen por modelo, últimos 7 días
  tokps.py --days 30            -> ventana distinta
  tokps.py --session <id|pref>  -> detalle mensaje a mensaje
  tokps.py --jsonl              -> métricas por mensaje en JSONL (stdout)
  tokps.py --min-out 200        -> mínimos tokens generados (out+reasoning, def 100)
"""
import argparse
import datetime
import json
import os
import sqlite3
from collections import defaultdict

DB = os.path.expanduser("~/.local/share/opencode/opencode.db")

Q = """
WITH a AS (
  SELECT id, session_id, time_created tc, data
  FROM message
  WHERE json_extract(data,'$.role')='assistant'
    AND json_extract(data,'$.time.completed') IS NOT NULL
    AND time_created > (strftime('%s','now')-:days*86400)*1000
),
tools AS (
  SELECT message_id,
         MIN(CAST(json_extract(data,'$.state.time.start') AS INTEGER)) t_first,
         SUM(MAX(CAST(json_extract(data,'$.state.time.end') AS INTEGER),0)
           - MAX(CAST(json_extract(data,'$.state.time.start') AS INTEGER),0)) tool_ms,
         COUNT(*) n_tools
  FROM part
  WHERE json_extract(data,'$.type')='tool'
    AND json_extract(data,'$.state.time.start') IS NOT NULL
  GROUP BY message_id
)
SELECT a.session_id, a.id, a.data,
       COALESCE(t.t_first, 0), COALESCE(t.tool_ms, 0), COALESCE(t.n_tools, 0)
FROM a LEFT JOIN tools t ON t.message_id = a.id
WHERE json_extract(a.data,'$.tokens.output') + COALESCE(json_extract(a.data,'$.tokens.reasoning'),0) >= :minout
  AND (:session IS NULL OR a.session_id LIKE :session)
ORDER BY a.tc
"""


def rows(days, min_out, session=None):
    con = sqlite3.connect(f"file:{DB}?mode=ro", uri=True)
    con.execute("PRAGMA query_only=1")
    for sid, mid, data, t_first, tool_ms, n_tools in con.execute(
        Q, {"days": days, "minout": min_out, "session": session or None}
    ):
        d = json.loads(data)
        t = d["time"]
        c0, c1 = t["created"], t["completed"]
        tok = d.get("tokens", {})
        out = tok.get("output", 0)
        reasoning = tok.get("reasoning", 0) or 0
        gen = out + reasoning
        span = (c1 - c0) / 1000.0
        llm_ms = (t_first - c0) if t_first else (c1 - c0)
        llm_s = llm_ms / 1000.0
        if llm_s <= 0:
            continue
        yield {
            "session": sid,
            "message": mid,
            "provider": d.get("providerID"),
            "model": d.get("modelID"),
            "finish": d.get("finish"),
            "gen": gen,
            "out": out,
            "reasoning": reasoning,
            "input": tok.get("input", 0),
            "cache_read": tok.get("cache", {}).get("read", 0),
            "span_s": round(span, 2),
            "llm_s": round(llm_s, 2),
            "tool_ms": tool_ms or 0,
            "n_tools": n_tools or 0,
            "tok_s_gen": round(gen / llm_s, 1),
            "created": c0,
        }
    con.close()


def agg(rs):
    by = defaultdict(list)
    for r in rs:
        by[f"{r['provider']}/{r['model']}"].append(r)
    out = []
    for k, v in by.items():
        sp = sorted(x["tok_s_gen"] for x in v)
        n = len(sp)
        out.append({
            "model": k, "msgs": n,
            "tok_s_p50": sp[n // 2],
            "tok_s_p90": sp[int(n * 0.9) - 1 if n > 1 else 0],
            "tok_s_max": sp[-1],
            "llm_p50_s": sorted(x["llm_s"] for x in v)[n // 2],
            "tool_s_med": sorted(x["tool_ms"] for x in v)[n // 2] / 1000.0,
            "gen_total": sum(x["gen"] for x in v),
        })
    return sorted(out, key=lambda x: -x["msgs"])


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--days", type=int, default=7)
    ap.add_argument("--min-out", type=int, default=100,
                    help="mínimo output+reasoning tokens")
    ap.add_argument("--session")
    ap.add_argument("--jsonl", action="store_true")
    a = ap.parse_args()

    rs = list(rows(a.days, a.min_out, a.session))
    if a.jsonl:
        for r in rs:
            print(json.dumps(r))
        return
    if a.session:
        print(f"{'hora':<13}{'modelo':<44}{'llm_s':>7}{'gen':>6}{'tok/s':>7}"
              f"{'tools':>6}{'tool_ms':>9}  finish")
        for r in rs:
            h = datetime.datetime.fromtimestamp(r["created"] / 1000).strftime("%m-%d %H:%M")
            m = f"{r['provider'][:12]}/{r['model']}"
            print(f"{h:<13}{m:<44}{r['llm_s']:>7}{r['gen']:>6}{r['tok_s_gen']:>7}"
                  f"{r['n_tools']:>6}{r['tool_ms']:>9}  {r['finish']}")
        return
    print(f"tok/s de GENERACIÓN puro (out+reasoning / ventana LLM sin tools) — "
          f"últimos {a.days}d — {len(rs)} mensajes\n")
    print(f"{'modelo':<50}{'msgs':>5}{'p50':>6}{'p90':>6}{'max':>6}"
          f"{'llm_p50':>9}{'tool_p50':>10}{'gen_tot':>9}")
    for m in agg(rs):
        print(f"{m['model']:<50}{m['msgs']:>5}{m['tok_s_p50']:>6}{m['tok_s_p90']:>6}"
              f"{m['tok_s_max']:>6}{m['llm_p50_s']:>9}{m['tool_s_med']:>10}{m['gen_total']:>9}")


if __name__ == "__main__":
    main()
