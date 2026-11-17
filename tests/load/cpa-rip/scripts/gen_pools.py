#!/usr/bin/env python3
"""Generate visitor pools: repeat (10000) and repeat-tight (50).

Each visitor = fixed (IP, UA) pair. IP is random public (private/reserved ranges
excluded). UA is a random base UA + fixed visitor suffix, so pairs never collide.
"""
import json
import os

SRC_DIR = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(SRC_DIR)
import random
import sys

random.seed(1337)
UAS = json.load(open(os.path.join(ROOT, 'data', 'uas.json')))


def is_private(a, b, c):
    if a in (0, 10, 127):
        return True
    if a == 100 and 64 <= b <= 127:
        return True
    if a == 169 and b == 254:
        return True
    if a == 172 and 16 <= b <= 31:
        return True
    if a == 192 and b in (0, 168):
        return True
    if a == 198 and b in (18, 19):
        return True
    if a == 203 and b == 0 and c == 113:
        return True
    return a >= 224


def rand_ip():
    while True:
        a = random.randint(1, 222)
        b = random.randint(0, 255)
        c = random.randint(0, 255)
        d = random.randint(1, 254)
        if not is_private(a, b, c):
            return f'{a}.{b}.{c}.{d}'


def gen(name, n, prefix):
    pool = []
    for i in range(n):
        ua = random.choice(UAS) + f'; b/{prefix}{i}'
        pool.append({'ip': rand_ip(), 'ua': ua})
    path = os.path.join(ROOT, 'data', f'{name}.json')
    json.dump(pool, open(path, 'w'))
    print(f'{path}: {n} visitors, {len({(v["ip"], v["ua"]) for v in pool})} unique pairs')


if __name__ == '__main__':
    for name, n, prefix in [('repeat10k', 10000, 'rep10k-'), ('repeat50', 50, 'rep50-')]:
        gen(name, n, prefix)
