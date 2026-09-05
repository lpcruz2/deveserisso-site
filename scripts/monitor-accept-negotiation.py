"""Monitor do experimento de negociacao por Accept header (2026-09-05).

Bate a mesma URL a cada minuto alternando Accept: text/html e
Accept: text/markdown, registrando o Content-Type recebido e os headers
de cache do hcdn (x-hcdn-cache-status, x-hcdn-request-id). Objetivo:
detectar se a variante errada (markdown pra quem pediu html, ou
vice-versa) aparece via cache, sem depender de log do servidor -- uma
requisicao servida do cache (HIT) nunca chega no PHP, entao o log do
lado da origem nao enxerga esse cenario.

Para no primeiro erro real detectado (nao espera o prazo de 24h) --
criterio de rollback e "qualquer erro", nao "taxa aceitavel apos X
tentativas".

Uso:
  python scripts/monitor-accept-negotiation.py --horas 24 --out resultado.jsonl
"""

import argparse
import json
import time
import urllib.request
from datetime import datetime, timezone

URL = "https://deveserisso.com.br/politicas-de-privacidade/"


def checar(accept: str) -> dict:
	req = urllib.request.Request(URL, headers={"Accept": accept, "User-Agent": "dsi-monitor-accept-negotiation/1.0"})
	try:
		with urllib.request.urlopen(req, timeout=15) as resp:
			headers = dict(resp.headers)
			return {
				"accept_enviado": accept,
				"status": resp.status,
				"content_type": headers.get("Content-Type", ""),
				"hcdn_cache_status": headers.get("x-hcdn-cache-status", ""),
				"hcdn_request_id": headers.get("x-hcdn-request-id", ""),
				"age": headers.get("Age", ""),
				"erro": None,
			}
	except Exception as e:  # noqa: BLE001 -- monitor nao pode cair por causa de um timeout de rede
		return {"accept_enviado": accept, "status": None, "content_type": "", "hcdn_cache_status": "", "hcdn_request_id": "", "age": "", "erro": str(e)}


def eh_erro_critico(resultado: dict) -> bool:
	ct = resultado["content_type"].lower()
	if resultado["accept_enviado"] == "text/html" and "text/markdown" in ct:
		return True  # humano pedindo html recebeu markdown -- pagina quebrada de verdade
	if resultado["accept_enviado"] == "text/markdown" and "text/html" in ct:
		return True  # agente pedindo markdown recebeu html -- negociacao nao funcionou
	return False


def main() -> None:
	ap = argparse.ArgumentParser()
	ap.add_argument("--horas", type=float, default=24.0)
	ap.add_argument("--intervalo-seg", type=int, default=60)
	ap.add_argument("--out", type=str, default="monitor-accept-negotiation-resultado.jsonl")
	args = ap.parse_args()

	fim = time.time() + args.horas * 3600
	edges_vistos = set()
	total = 0
	erros = 0

	with open(args.out, "a", encoding="utf-8") as f:
		while time.time() < fim:
			for accept in ("text/html", "text/markdown"):
				r = checar(accept)
				r["timestamp"] = datetime.now(timezone.utc).isoformat()
				r["critico"] = eh_erro_critico(r)
				total += 1
				if r["hcdn_request_id"]:
					# formato observado: "<hash-32-hex>-phx-edge5" -- guarda so o
					# sufixo de identificacao do edge (tudo apos o primeiro "-")
					partes = r["hcdn_request_id"].split("-", 1)
					edges_vistos.add(partes[1] if len(partes) > 1 else r["hcdn_request_id"])
				f.write(json.dumps(r, ensure_ascii=False) + "\n")
				f.flush()
				if r["critico"]:
					erros += 1
					print(f"ERRO CRITICO detectado: {r}")
					print(f"Parando o monitor -- criterio de rollback atingido apos {total} checagens.")
					print(f"Edges de hcdn amostrados nesta execucao: {sorted(edges_vistos)}")
					return
			time.sleep(args.intervalo_seg)

	print(f"Monitor concluido sem erro critico. Total de checagens: {total}. Edges amostrados: {sorted(edges_vistos)}")


if __name__ == "__main__":
	main()
